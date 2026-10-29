<?php

declare(strict_types=1);

namespace Asin\Tma\Tests;

use Asin\Tma\DemandeRendezVous;
use Asin\Tma\ReglesRendezVous;
use Asin\Tma\RendezVous;
use Asin\Tma\Resultat;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

abstract class ReglesRendezVousTestCase extends TestCase
{
    protected ReglesRendezVous $regles;

    protected function setUp(): void
    {
        $this->regles = new ReglesRendezVous();
    }

    protected function dt(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, new DateTimeZone('Africa/Porto-Novo'));
    }

    protected function rdv(
        int $id,
        string $npi,
        string $service,
        string $dateHeure,
        string $statut = 'confirme',
    ): RendezVous {
        return new RendezVous($id, $npi, $service, $this->dt($dateHeure), $statut);
    }

    protected function demande(string $npi, string $service, string $dateHeure): DemandeRendezVous
    {
        return new DemandeRendezVous($npi, $service, $dateHeure);
    }

    protected function assertRefus(Resultat $resultat, int $code, string $message): void
    {
        self::assertFalse($resultat->ok);
        self::assertSame($code, $resultat->code);
        self::assertSame($message, $resultat->message);
    }

    protected function assertSucces(Resultat $resultat, int $code): void
    {
        self::assertTrue($resultat->ok);
        self::assertSame($code, $resultat->code);
        self::assertNull($resultat->message);
    }

    /**
     * Les 13 rendez-vous de l'usager 0203040506, tels qu'ils figurent dans
     * l'export. Ils servent au test TMA-106 et aux tests de pagination.
     *
     * @return list<RendezVous>
     */
    protected function rendezVousUsager13(): array
    {
        return [
            $this->rdv(826, '0203040506', 'PASSEPORT', '2026-12-02 08:30', 'confirme'),
            $this->rdv(259, '0203040506', 'PERMIS', '2026-09-25 11:00', 'honore'),
            $this->rdv(11, '0203040506', 'CNI', '2026-08-11 14:00', 'honore'),
            $this->rdv(10, '0203040506', 'PASSEPORT', '2026-07-28 09:30', 'absent'),
            $this->rdv(9, '0203040506', 'PERMIS', '2026-07-07 10:30', 'honore'),
            $this->rdv(8, '0203040506', 'CNI', '2026-06-16 13:30', 'honore'),
            $this->rdv(7, '0203040506', 'PASSEPORT', '2026-06-02 08:00', 'honore'),
            $this->rdv(6, '0203040506', 'PERMIS', '2026-05-19 15:00', 'absent'),
            $this->rdv(5, '0203040506', 'CNI', '2026-05-08 11:30', 'honore'),
            $this->rdv(4, '0203040506', 'PASSEPORT', '2026-04-21 09:00', 'honore'),
            $this->rdv(3, '0203040506', 'PERMIS', '2026-03-03 14:30', 'absent'),
            $this->rdv(2, '0203040506', 'CNI', '2026-02-12 10:00', 'honore'),
            $this->rdv(1, '0203040506', 'PASSEPORT', '2026-01-05 08:30', 'honore'),
        ];
    }
}
