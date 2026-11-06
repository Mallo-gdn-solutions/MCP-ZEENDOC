<?php
/**
 * Sources de détail des paiements groupés — module de rapprochement GDN
 * ---------------------------------------------------------------------
 * Un débit bancaire peut régler plusieurs factures (virement groupé). Une « source » fournit,
 * pour chaque paiement groupé, la liste exacte des factures réglées.
 *
 * Sources disponibles (module-config.json → paiementsGroupes.sources, dans l'ordre) :
 *   - "fichier"          : fichier JSON tenu à la main ou exporté d'un outil (Libeo, Agicap, remise SEPA…)
 *   - "fintecture-graph" : mails « Votre paiement est validé » de Fintecture, lus dans Microsoft 365 (Graph)
 * Pour une nouvelle source : écrire une classe qui implémente SourcePaiementsGroupes et l'ajouter à sp_creer().
 *
 * Format commun d'un paiement :
 *   ['transaction' => identifiant unique, 'date' => AAAA-MM-JJ, 'beneficiaire' => ..., 'cents' => int,
 *    'reference' => libellé brut, 'factures' => [n° de facture...], 'source' => 'Fintecture', 'preuve' => texte écrit dans Zeendoc]
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rapprochement.php';

interface SourcePaiementsGroupes
{
    /** Nom lisible (affiché dans l'interface et les rapports) */
    public function nom(): string;

    /**
     * Paiements groupés de la période (dates AAAA-MM-JJ, bornes incluses).
     * @return array{paiements: array, anomalies: array} anomalies = ce qui n'a pas pu être lu, sans bloquer le reste
     */
    public function lire(string $du, string $au): array;
}

/** Montant « 8 472,54 € » / « 8472.54 » → centimes */
function sp_cents(string $montant): int
{
    return rb_cents(str_replace(['€', "\xE2\x80\xAF"], '', $montant));
}

/** Liste des factures d'une référence de virement : ce qui suit le dernier « - », découpé sur les virgules */
function sp_factures(string $reference): array
{
    $p = strrpos($reference, ' - ');
    $liste = $p === false ? $reference : substr($reference, $p + 3);
    return array_values(array_filter(array_map('trim', explode(',', $liste)), 'strlen'));
}

/* ------------------------------------------------------------
 * Source « fichier » (JSON)
 * ------------------------------------------------------------ */

/**
 * Deux formes acceptées :
 *   {"<transaction>": {"date": "2026-01-05", "montant": "961,20", "fournisseur": "SAGES", "factures": ["FA153337"]}, ...}
 *   [{"transaction": "...", "date": "...", "montant": "...", "beneficiaire": "...", "factures": [...]}, ...]
 * Les clés commençant par « _ » sont des commentaires.
 */
final class SourceFichier implements SourcePaiementsGroupes
{
    public function __construct(private string $fichier, private string $libelle = 'Fichier') {}

    public function nom(): string { return $this->libelle; }

    public function lire(string $du, string $au): array
    {
        if (!is_file($this->fichier)) return ['paiements' => [], 'anomalies' => []];
        $j = json_decode((string) file_get_contents($this->fichier), true);
        if (!is_array($j)) return ['paiements' => [], 'anomalies' => [['source' => $this->nom(), 'message' => basename($this->fichier) . ' illisible (JSON invalide)']]];
        $out = [];
        $anomalies = [];
        foreach ($j as $cle => $p) {
            if (str_starts_with((string) $cle, '_') || !is_array($p)) continue;
            $tx = (string) ($p['transaction'] ?? $cle);
            $date = rb_date((string) ($p['date'] ?? ''));
            $factures = array_values(array_filter(array_map('trim', (array) ($p['factures'] ?? [])), 'strlen'));
            if (!$date || !$factures || !isset($p['montant'])) {
                $anomalies[] = ['source' => $this->nom(), 'message' => "Entrée « $tx » incomplète (date, montant ou factures manquants)"];
                continue;
            }
            if ($date < $du || $date > $au) continue;
            $out[] = ['transaction' => $tx, 'date' => $date, 'beneficiaire' => (string) ($p['beneficiaire'] ?? $p['fournisseur'] ?? ''),
                      'cents' => sp_cents((string) $p['montant']), 'reference' => (string) ($p['reference'] ?? implode(', ', $factures)),
                      'factures' => $factures, 'source' => $this->nom(), 'preuve' => (string) ($p['preuve'] ?? $this->nom() . ' ' . $tx)];
        }
        return ['paiements' => $out, 'anomalies' => $anomalies];
    }
}

/* ------------------------------------------------------------
 * Source « Fintecture » : mails lus dans Microsoft 365
 * ------------------------------------------------------------ */

/** Texte lisible d'un fragment HTML (entités, espaces insécables) */
function fin_texte(string $html): string
{
    // Balise remplacée par une espace : « …6d</em></p><p>L’équipe » ne doit pas coller le n° de transaction au mot suivant
    $t = html_entity_decode(strip_tags((string) preg_replace('/<[^>]*>/', ' $0', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $t);
    return trim((string) preg_replace('/\s+/u', ' ', $t));
}

/**
 * Lit un mail Fintecture « Votre paiement est validé ».
 * Deux gabarits connus : « Récapitulatif du paiement » (Référence, date AAAA-MM-JJ)
 * et « Récapitulatif de l'opération » (Libellé relevé bancaire, date JJ/MM/AAAA HH:MM).
 * @return array ['ok' => true, 'paiement' => [...]] ou ['ok' => false, 'raison' => '...']
 */
function fin_lire_mail(string $html): array
{
    $texte = fin_texte($html);
    if (!preg_match('/R[ée]capitulatif (du paiement|de l[’\']op[ée]ration)/iu', $texte)) {
        return ['ok' => false, 'raison' => 'gabarit inconnu (pas de bloc « Récapitulatif du paiement / de l\'opération »)'];
    }
    // Lignes « Libellé <strong>valeur</strong> » du bloc récapitulatif
    $champs = [];
    if (preg_match_all('#<div[^>]*details-section-row[^>]*>(.*?)</div>#isu', $html, $lignes)) {
        foreach ($lignes[1] as $l) {
            if (!preg_match('#^(.*?)<strong[^>]*>(.*?)</strong>#isu', $l, $m)) continue;
            $champs[strtolower(fin_texte($m[1]))] = fin_texte($m[2]); // strtolower : ASCII seul, « é » reste « é »
        }
    }
    $val = function (array $noms) use ($champs): string {
        foreach ($noms as $n) { if (($champs[$n] ?? '') !== '') return $champs[$n]; }
        return '';
    };
    $date = rb_date($val(['date']));
    $benef = $val(['bénéficiaire', 'beneficiaire']);
    $montant = $val(['montant']);
    $reference = $val(['référence', 'reference', 'libellé relevé bancaire', 'libelle releve bancaire']);
    $tx = preg_match('/r[ée]f[ée]rence unique de transaction est\s*:\s*([0-9a-f]{32})\b/iu', $texte, $m) ? strtolower($m[1]) : '';

    $manque = [];
    if (!$date) $manque[] = 'date';
    if ($benef === '') $manque[] = 'bénéficiaire';
    if ($montant === '' || sp_cents($montant) === 0) $manque[] = 'montant';
    if ($reference === '') $manque[] = 'référence';
    if ($tx === '') $manque[] = 'référence de transaction';
    if ($manque) return ['ok' => false, 'raison' => 'champ(s) introuvable(s) : ' . implode(', ', $manque)];

    return ['ok' => true, 'paiement' => [
        'transaction' => $tx, 'date' => $date, 'beneficiaire' => $benef, 'cents' => sp_cents($montant),
        'reference' => $reference, 'factures' => sp_factures($reference), 'source' => 'Fintecture', 'preuve' => "Fintecture $tx",
    ]];
}

/**
 * Cache local (cache-paiements/fintecture.json) : on ne relit pas un mail déjà analysé.
 *   paiements : transaction → paiement   ·   messages : id du mail → transaction, « ignoré » ou « illisible »
 *   illisibles : id du mail → {recu, objet, raison}   ·   depuis : date de début déjà parcourue
 */
final class SourceFintectureGraph implements SourcePaiementsGroupes
{
    private string $cache;

    public function __construct(private array $reglages, ?string $cache = null)
    {
        $this->cache = $cache ?? __DIR__ . '/cache-paiements/fintecture.json';
    }

    public function nom(): string { return 'Fintecture'; }

    private function chargerCache(): array
    {
        $c = is_file($this->cache) ? json_decode((string) file_get_contents($this->cache), true) : null;
        return (is_array($c) ? $c : []) + ['paiements' => [], 'messages' => [], 'illisibles' => [], 'depuis' => null];
    }

    /** Analyse des mails déjà récupérés (séparée de Graph pour pouvoir la tester) */
    public function integrer(array $mails, array $cache): array
    {
        $objet = (string) ($this->reglages['objet'] ?? '/paiement est valid/i');
        foreach ($mails as $m) {
            if (isset($cache['messages'][$m['id']])) continue;
            if (!@preg_match($objet, $m['objet'])) { $cache['messages'][$m['id']] = 'ignoré'; continue; }
            $r = fin_lire_mail($m['html']);
            if ($r['ok']) {
                $cache['paiements'][$r['paiement']['transaction']] = $r['paiement'];
                $cache['messages'][$m['id']] = $r['paiement']['transaction'];
            } else {
                $cache['messages'][$m['id']] = 'illisible';
                $cache['illisibles'][$m['id']] = ['recu' => $m['recu'], 'objet' => $m['objet'], 'raison' => $r['raison']];
            }
        }
        return $cache;
    }

    public function lire(string $du, string $au): array
    {
        $cache = $this->chargerCache();
        $anomalies = [];
        try {
            $reg = gr_reglages($this->reglages);
            if (!gr_connecte($reg)) {
                $anomalies[] = ['source' => $this->nom(), 'message' => 'Boîte mail Microsoft 365 non connectée : lancer connecter-outlook.bat une fois. Seuls les paiements déjà en cache sont utilisés.'];
            } else {
                // Période déjà parcourue : on ne relit que les mails arrivés depuis la dernière lecture (3 jours de recouvrement)
                $dejaLu = $cache['depuis'] && $cache['depuis'] <= $du && !empty($cache['derniereLecture']);
                $depuis = $dejaLu ? max($du, rb_ajout((string) $cache['derniereLecture'], -3)) : $du;
                $mails = gr_mails($reg, (string) ($this->reglages['expediteur'] ?? 'noreply@fintecture.com'), $depuis);
                $cache = $this->integrer($mails, $cache);
                $cache['depuis'] = $cache['depuis'] ? min($cache['depuis'], $du) : $du;
                $cache['derniereLecture'] = date('Y-m-d');
                if (!is_dir(dirname($this->cache))) mkdir(dirname($this->cache), 0700, true);
                file_put_contents($this->cache, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            }
        } catch (Throwable $e) {
            $anomalies[] = ['source' => $this->nom(), 'message' => 'Lecture des mails impossible : ' . $e->getMessage() . ' Seuls les paiements déjà en cache sont utilisés.'];
        }
        foreach ($cache['illisibles'] as $id => $x) {
            if (($x['recu'] ?? '') < $du || ($x['recu'] ?? '') > $au) continue;
            $anomalies[] = ['source' => $this->nom(), 'message' => 'Mail du ' . $x['recu'] . ' « ' . $x['objet'] . ' » non lu : ' . $x['raison'], 'mail' => $id];
        }
        $paiements = array_values(array_filter($cache['paiements'], fn($p) => $p['date'] >= $du && $p['date'] <= $au));
        return ['paiements' => $paiements, 'anomalies' => $anomalies];
    }
}

/* ------------------------------------------------------------
 * Assemblage des sources configurées
 * ------------------------------------------------------------ */

/** Réglages des sources : module-config.json, sinon le fichier historique paiements-groupes.json seul */
function sp_reglages(): array
{
    $c = module_cfg()['paiementsGroupes'] ?? null;
    if (!is_array($c)) return ['sources' => [['type' => 'fichier', 'fichier' => 'paiements-groupes.json']], 'joursOuvres' => 5];
    return $c + ['sources' => [], 'joursOuvres' => 5];
}

function sp_creer(array $s): SourcePaiementsGroupes
{
    switch ($s['type'] ?? '') {
        case 'fichier':
            $f = basename((string) ($s['fichier'] ?? 'paiements-groupes.json'));
            return new SourceFichier(__DIR__ . '/' . $f, (string) ($s['nom'] ?? 'Fichier ' . $f));
        case 'fintecture-graph':
            require_once __DIR__ . '/graph.php';
            return new SourceFintectureGraph($s);
    }
    throw new RuntimeException('Source de paiements groupés inconnue : « ' . ($s['type'] ?? '') . ' » (types : fichier, fintecture-graph)');
}

/**
 * Lit toutes les sources ; un même paiement présent dans deux sources n'est gardé qu'une fois (première source).
 * @return array{paiements: array, anomalies: array, sources: array}
 */
function sp_lire_tout(string $du, string $au, ?array $reglages = null): array
{
    $reglages ??= sp_reglages();
    $paiements = [];
    $anomalies = [];
    $noms = [];
    foreach ((array) $reglages['sources'] as $s) {
        if (($s['actif'] ?? true) === false) continue;
        try {
            $src = sp_creer((array) $s);
            $noms[] = $src->nom();
            $r = $src->lire($du, $au);
        } catch (Throwable $e) {
            $anomalies[] = ['source' => (string) ($s['type'] ?? '?'), 'message' => $e->getMessage()];
            continue;
        }
        foreach ($r['paiements'] as $p) $paiements[$p['transaction']] ??= $p;
        array_push($anomalies, ...$r['anomalies']);
    }
    return ['paiements' => array_values($paiements), 'anomalies' => $anomalies, 'sources' => $noms];
}
