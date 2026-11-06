<?php

define('NOYAU_JOURNAL', sys_get_temp_dir() . '/noyau-test-journal-' . getmypid() . '.jsonl');

require __DIR__ . '/../noyau/noyau.php';
require __DIR__ . '/../noyau/outils.php';

$tests = 0;
$echoues = 0;

function ok(bool $condition, string $message): void
{
    global $tests, $echoues;
    $tests++;
    if (!$condition) {
        $echoues++;
        fwrite(STDERR, "ÉCHEC : $message\n");
    } else {
        echo "ok : $message\n";
    }
}

ok(rb_norm('HÉLLO  Wörld! Éàü') === 'hello world eau', 'outils : rb_norm normalise casse, accents, ponctuation');
ok(rb_date('31/01/2026') === '2026-01-31', 'outils : rb_date JJ/MM/AAAA');
ok(rb_date('2026-10-05') === '2026-10-05', 'outils : rb_date AAAA-MM-JJ conservé');
ok(rb_date('32/13/2026') === null && rb_date('') === null && rb_date(null) === null, 'outils : rb_date invalide -> null');
ok(rb_ajout('2026-01-31', 1) === '2026-02-01', 'outils : rb_ajout franchit le mois');
ok(rb_jours('2026-10-01', '2026-10-11') === 10, 'outils : rb_jours = b - a en jours');
ok(rb_cents('1 234,56') === 123456 && rb_cents('1.234,56 €') === 123456 && rb_cents('-12,00') === -1200 && rb_cents('1234.56') === 123456, 'outils : rb_cents parse montants FR/EN');
ok(rb_eur(123456) === '1 234,56' && rb_eur(-1200) === '-12,00', 'outils : rb_eur formatte en euros');
ok(rb_compact('FA-2026/001 b') === 'FA2026001B', 'outils : rb_compact garde alphanum majuscules');

$fixture = __DIR__ . '/fixtures/module-config.test.json';
$c = armoire_lire($fixture);
ok(armoire_url_client($c) === 'gdn_solutions', 'armoire : urlClient lu');
ok(armoire_host($c) === 'https://zeendoc.test', 'armoire : host lu');
ok(armoire_classeur($c, 'fournisseurs')['index']['ttc'] === 'custom_f2', 'armoire : classeur + index lus');
ok(armoire_classeur($c, 'inconnu') === null, 'armoire : classeur absent -> null');
ok(armoire_a_remplir($c) === [], 'armoire : config complète sans erreur');
ok(armoire_verifier_classeur($c, 'fournisseurs', ['tiers', 'ttc']) === [], 'armoire : classeur valide sans erreur');
$err = armoire_verifier_classeur($c, 'clients', ['tiers', 'ttc', 'echeance']);
ok(in_array('classeurs.clients.index.echeance', $err, true), 'armoire : index manquant signalé');

ok(noyau_route_autorisee('lecture', 'GET', '/context'), 'routes : GET /context autorisé en lecture');
ok(noyau_route_autorisee('lecture', 'POST', '/binders/coll_13/documents/search'), 'routes : search autorisé en lecture');
ok(!noyau_route_autorisee('lecture', 'PATCH', '/binders/coll_13/documents/1/indexes'), 'routes : PATCH indexes refusé en lecture');
ok(!noyau_route_autorisee('lecture', 'DELETE', '/binders/coll_13/documents/1'), 'routes : DELETE refusé');
$classeurs = ['ecriture' => ['coll_13', 'coll_14'], 'depot' => ['coll_13']];
ok(noyau_route_autorisee('ecriture', 'PATCH', '/binders/coll_13/documents/123/indexes', $classeurs), 'routes : PATCH indexes autorisé sur classeur du module');
ok(!noyau_route_autorisee('ecriture', 'PATCH', '/binders/coll_99/documents/123/indexes', $classeurs), 'routes : PATCH indexes refusé hors classeurs du module');
ok(noyau_route_autorisee('ecriture', 'POST', '/binders/coll_13/documents', $classeurs), 'routes : dépôt autorisé sur classeur depot');
ok(!noyau_route_autorisee('ecriture', 'POST', '/binders/coll_15/documents', $classeurs), 'routes : dépôt refusé hors classeur depot');
ok(noyau_route_autorisee('ecriture', 'POST', '/login', $classeurs), 'routes : login autorisé en écriture');

@unlink(NOYAU_JOURNAL);
noyau_journal_ajouter(['type' => 'test', 'resId' => 123, 'avant' => ['statut' => null], 'apres' => ['statut' => 'Payé']]);
noyau_journal_ajouter(['type' => 'test', 'resId' => 124]);
$lignes = noyau_journal_lire();
ok(count($lignes) === 2, 'journal : deux lignes relues');
ok($lignes[0]['resId'] === 123 && isset($lignes[0]['quand']), 'journal : entrée tracée avec horodatage');
@unlink(NOYAU_JOURNAL);

echo "\n$tests tests, $echoues échec(s)\n";
exit($echoues > 0 ? 1 : 0);
