<?php

declare(strict_types=1);

namespace Asin\Tma\Tests;

use Asin\Tma\PageRendezVous;
use Asin\Tma\ReglesRendezVous;

final class LimitesTest extends ReglesRendezVousTestCase
{
    public function testNpiDeNeufOuOnzeChiffresEstRefuse(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');

        foreach (['061425364', '06142536470'] as $npi) {
            $resultat = $this->regles->prendreRendezVous(
                $this->demande($npi, 'CNI', '2026-11-23T09:00'),
                $maintenant,
            );

            $this->assertRefus($resultat, 400, ReglesRendezVous::MESSAGE_NPI_INVALIDE);
        }
    }

    public function testDatePasseeRefusee(): void
    {
        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0614253647', 'CNI', '2026-11-19T10:00'),
            $this->dt('2026-11-20 18:00'),
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_DATE_PASSEE);
    }

    public function testDateEgaleALInstantPresentRefusee(): void
    {
        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0614253647', 'CNI', '2026-11-20T18:00'),
            $this->dt('2026-11-20 18:00'),
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_DATE_PASSEE);
    }

    public function testBornesDeLaGrilleHoraire(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');

        $cas = [
            ['2026-11-26T07:30', 409, ReglesRendezVous::MESSAGE_CRENEAU_INVALIDE],
            ['2026-11-23T08:00', 201, null],
            ['2026-11-24T15:30', 201, null],
            ['2026-11-25T16:00', 409, ReglesRendezVous::MESSAGE_CRENEAU_INVALIDE],
            ['2026-11-27T08:15', 409, ReglesRendezVous::MESSAGE_CRENEAU_INVALIDE],
        ];

        foreach ($cas as [$dateHeure, $code, $message]) {
            $resultat = $this->regles->prendreRendezVous(
                $this->demande('0614253647', 'CNI', $dateHeure),
                $maintenant,
            );

            if ($message === null) {
                $this->assertSucces($resultat, $code);
            } else {
                $this->assertRefus($resultat, $code, $message);
            }
        }
    }

    public function testUnRendezVousAnnuleLibereUnePlace(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');
        $creneau = [
            $this->rdv(1, '0638958971', 'PASSEPORT', '2026-11-10 09:00'),
            $this->rdv(2, '0671968463', 'CNI', '2026-11-10 09:00'),
            $this->rdv(3, '0609394161', 'PERMIS', '2026-11-10 09:00', 'annule'),
        ];

        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0700000002', 'PERMIS', '2026-11-10T09:00'),
            $maintenant,
            [],
            $creneau,
        );

        $this->assertSucces($resultat, 201);
    }

    public function testAnnulationALaBorneDeVingtQuatreHeuresAutorisee(): void
    {
        $rendezVous = $this->rdv(709, '0339205992', 'PERMIS', '2026-11-19 10:00', 'confirme');

        $resultat = $this->regles->annulerRendezVous($rendezVous, $this->dt('2026-11-18 10:00'));

        $this->assertSucces($resultat, 200);
        self::assertSame('annule', $resultat->donnees->statut);
    }

    public function testAnnulationAMoinsDeVingtQuatreHeuresRefusee(): void
    {
        $rendezVous = $this->rdv(709, '0339205992', 'PERMIS', '2026-11-19 10:00', 'confirme');

        $resultat = $this->regles->annulerRendezVous($rendezVous, $this->dt('2026-11-18 10:01'));

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_ANNULATION_TARDIVE);
    }

    public function testAnnulationDUnRendezVousNonConfirmeRefusee(): void
    {
        foreach (['honore', 'absent', 'annule'] as $statut) {
            $rendezVous = $this->rdv(1, '0339205992', 'PERMIS', '2026-11-19 10:00', $statut);

            $resultat = $this->regles->annulerRendezVous($rendezVous, $this->dt('2026-11-17 18:00'));

            $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_NON_ANNULE);
        }
    }

    public function testStatutDemandeInvalideRefuse(): void
    {
        $rendezVous = $this->rdv(1, '0339205992', 'PERMIS', '2026-11-19 10:00', 'confirme');

        $resultat = $this->regles->changerStatut($rendezVous, 'confirme');

        $this->assertRefus($resultat, 400, ReglesRendezVous::MESSAGE_STATUT_INVALIDE);
    }

    public function testTransitionDepuisUnStatutDefinitifRefusee(): void
    {
        foreach (['honore', 'absent', 'annule'] as $statut) {
            $rendezVous = $this->rdv(1, '0339205992', 'PERMIS', '2026-11-19 10:00', $statut);

            $resultat = $this->regles->changerStatut($rendezVous, 'absent');

            $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_DEJA_CLOTURE);
        }
    }

    public function testChangementDeStatutDepuisConfirmeAccepte(): void
    {
        $rendezVous = $this->rdv(1, '0339205992', 'PERMIS', '2026-11-19 10:00', 'confirme');

        foreach (['honore', 'absent'] as $statut) {
            $resultat = $this->regles->changerStatut($rendezVous, $statut);

            $this->assertSucces($resultat, 200);
            self::assertSame($statut, $resultat->donnees->statut);
        }
    }

    public function testPaginationHorsBornesRetourneUneListeVide(): void
    {
        foreach ([0, 99] as $page) {
            $resultat = $this->regles->listerRendezVous($this->rendezVousUsager13(), $page);

            $this->assertSucces($resultat, 200);
            self::assertInstanceOf(PageRendezVous::class, $resultat->donnees);
            self::assertSame(13, $resultat->donnees->total);
            self::assertCount(0, $resultat->donnees->rendezVous);
        }
    }

    public function testPaginationSurUneListeVide(): void
    {
        $resultat = $this->regles->listerRendezVous([], 1);

        $this->assertSucces($resultat, 200);
        self::assertInstanceOf(PageRendezVous::class, $resultat->donnees);
        self::assertSame(0, $resultat->donnees->total);
        self::assertSame(0, $resultat->donnees->nombreDePages());
        self::assertCount(0, $resultat->donnees->rendezVous);
    }

    public function testTriParDatePuisParIdentifiantDecroissant(): void
    {
        $rendezVous = [
            $this->rdv(5, '0203040506', 'CNI', '2026-11-19 10:00', 'honore'),
            $this->rdv(9, '0203040506', 'CNI', '2026-11-19 10:00', 'honore'),
        ];

        $resultat = $this->regles->listerRendezVous($rendezVous, 1);

        self::assertSame([9, 5], array_map(static fn ($rdv) => $rdv->id, $resultat->donnees->rendezVous));
    }

    public function testServiceInconnuRefuse(): void
    {
        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0614253647', 'AUTRE', '2026-11-23T09:00'),
            $this->dt('2026-11-01 12:00'),
        );

        $this->assertRefus($resultat, 400, ReglesRendezVous::MESSAGE_SERVICE_INCONNU);
    }

    public function testFormatsDeDateInvalidesRefuses(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');

        foreach ([
            '2026-11-23 09:00',
            '2026-11-23T09:00:00',
            '2026-11-23T9:00',
            '2026-02-30T09:00',
        ] as $dateHeure) {
            $resultat = $this->regles->prendreRendezVous(
                $this->demande('0614253647', 'CNI', $dateHeure),
                $maintenant,
            );

            $this->assertRefus($resultat, 400, ReglesRendezVous::MESSAGE_DATE_INVALIDE);
        }
    }

    public function testDimancheRefuse(): void
    {
        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0614253647', 'CNI', '2026-11-22T10:00'),
            $this->dt('2026-11-01 12:00'),
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_WEEKEND);
    }

    public function testDoublonMemeNpiMemeCreneauRefuse(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');
        $existant = $this->rdv(43, '0536455681', 'PERMIS', '2026-11-26 10:00');

        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0536455681', 'PASSEPORT', '2026-11-26T10:00'),
            $maintenant,
            [$existant],
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_DEJA_A_VENIR);
    }
}
