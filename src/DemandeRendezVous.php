<?php

declare(strict_types=1);

namespace Asin\Tma;

/**
 * Donnees brutes d'une demande de rendez-vous, telles qu'elles arrivent
 * depuis une API ou un formulaire.
 */
final readonly class DemandeRendezVous
{
    public function __construct(
        public string $npi,
        public string $service,
        public string $dateHeure,
    ) {
    }
}
