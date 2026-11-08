<?php

interface ModuleMetier
{
    public function identifiant(): string;

    public function description(): string;

    public function routesEcritureRequises(): array;

    public function executer(Noyau $noyau, array $options): array;
}

final class RegistreUsages
{
    private array $modules = [];
    private array $ecrituresAccordees = [];

    public function enregistrer(ModuleMetier $module): void
    {
        $this->modules[$module->identifiant()] = $module;
    }

    public function module(string $identifiant): ?ModuleMetier
    {
        return $this->modules[$identifiant] ?? null;
    }

    public function identifiants(): array
    {
        return array_keys($this->modules);
    }

    public function accorderEcriture(string $usage, string $route): void
    {
        $this->ecrituresAccordees[$usage][$route] = true;
    }

    public function autoriseEcriture(string $usage, string $route): bool
    {
        return $this->ecrituresAccordees[$usage][$route] ?? false;
    }
}
