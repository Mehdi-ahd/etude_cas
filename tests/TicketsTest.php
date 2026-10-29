<?php

declare(strict_types=1);

namespace Asin\Tma\Tests;

use Asin\Tma\PageRendezVous;
use Asin\Tma\ReglesRendezVous;

final class TicketsTest extends ReglesRendezVousTestCase
{
    /**
     * TMA-101 : rendez-vous un samedi, le centre est ferme.
     */
    public function testTma101RefuseUnRendezVousLeSamedi(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');

        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0614253647', 'PASSEPORT', '2026-11-14T10:00'),
            $maintenant,
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_WEEKEND);
    }

    /**
     * TMA-102 : quatre usagers sur le creneau du 10/11 a 09:00.
     * Le quatrieme doit etre refuse, meme si les services sont differents.
     */
    public function testTma102RefuseLeQuatriemeRendezVousSurUnCreneau(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');
        $creneau = [
            $this->rdv(632, '0638958971', 'PASSEPORT', '2026-11-10 09:00'),
            $this->rdv(644, '0671968463', 'CNI', '2026-11-10 09:00'),
            $this->rdv(681, '0609394161', 'PERMIS', '2026-11-10 09:00'),
        ];

        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0700000001', 'CNI', '2026-11-10T09:00'),
            $maintenant,
            [],
            $creneau,
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_CRENEAU_COMPLET);
    }

    /**
     * TMA-103 : NPI contenant une lettre. L'application renvoyait 500.
     */
    public function testTma103RefuseUnNpiNonNumeriqueSansErreurInterne(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');

        $resultat = $this->regles->prendreRendezVous(
            $this->demande('06142A3647', 'CNI', '2026-11-23T09:00'),
            $maintenant,
        );

        $this->assertRefus($resultat, 400, ReglesRendezVous::MESSAGE_NPI_INVALIDE);
    }

    /**
     * TMA-104 : annulation le 17/11 a 18:00 d'un rendez-vous le 19/11 a 10:00.
     * Il reste 40 heures : l'annulation doit etre acceptee.
     */
    public function testTma104AccepteUneAnnulationAQuaranteHeures(): void
    {
        $maintenant = $this->dt('2026-11-17 18:00');
        $rendezVous = $this->rdv(709, '0339205992', 'PERMIS', '2026-11-19 10:00', 'confirme');

        $resultat = $this->regles->annulerRendezVous($rendezVous, $maintenant);

        $this->assertSucces($resultat, 200);
        self::assertInstanceOf(\Asin\Tma\RendezVous::class, $resultat->donnees);
        self::assertSame('annule', $resultat->donnees->statut);

        $heuresRestantes = ($resultat->donnees->dateHeure->getTimestamp() - $maintenant->getTimestamp()) / 3600;
        self::assertSame(40.0, (float) $heuresRestantes);
    }

    /**
     * TMA-105 : un rendez-vous annule ne peut plus passer a « honore ».
     */
    public function testTma105RefuseUneTransitionDepuisAnnule(): void
    {
        $rendezVous = $this->rdv(292, '0330677555', 'PASSEPORT', '2026-11-12 10:00', 'annule');

        $resultat = $this->regles->changerStatut($rendezVous, 'honore');

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_DEJA_CLOTURE);
    }

    /**
     * TMA-106 : la page 1 doit commencer par le prochain rendez-vous du
     * 02/12/2026, puis les rendez-vous passes du plus recent au plus ancien.
     */
    public function testTma106TrieEtPagineLesRendezVous(): void
    {
        $resultat = $this->regles->listerRendezVous($this->rendezVousUsager13(), 1);

        $this->assertSucces($resultat, 200);
        self::assertInstanceOf(PageRendezVous::class, $resultat->donnees);

        $page = $resultat->donnees;
        self::assertSame(1, $page->page);
        self::assertSame(10, $page->parPage);
        self::assertSame(13, $page->total);
        self::assertSame(2, $page->nombreDePages());
        self::assertCount(10, $page->rendezVous);

        $identifiants = array_map(static fn ($rdv) => $rdv->id, $page->rendezVous);
        self::assertSame([826, 259, 11, 10, 9, 8, 7, 6, 5, 4], $identifiants);
        self::assertSame('2026-12-02 08:30', $page->rendezVous[0]->dateHeure->format('Y-m-d H:i'));

        $page2 = $this->regles->listerRendezVous($this->rendezVousUsager13(), 2)->donnees;
        self::assertInstanceOf(PageRendezVous::class, $page2);
        self::assertSame([3, 2, 1], array_map(static fn ($rdv) => $rdv->id, $page2->rendezVous));

        $page3 = $this->regles->listerRendezVous($this->rendezVousUsager13(), 3)->donnees;
        self::assertInstanceOf(PageRendezVous::class, $page3);
        self::assertCount(0, $page3->rendezVous);
    }

    /**
     * TMA-107 : comportement conforme. Un usager qui a deja un rendez-vous a
     * venir ne peut pas en prendre un second pour un autre service.
     */
    public function testTma107RefuseUnSecondRendezVousAVenirConformementALaRegle(): void
    {
        $maintenant = $this->dt('2026-11-01 12:00');
        $rendezVousExistant = $this->rdv(800, '0102030405', 'PASSEPORT', '2026-11-26 10:00');

        $resultat = $this->regles->prendreRendezVous(
            $this->demande('0102030405', 'PERMIS', '2026-11-27T09:00'),
            $maintenant,
            [$rendezVousExistant],
        );

        $this->assertRefus($resultat, 409, ReglesRendezVous::MESSAGE_DEJA_A_VENIR);
    }
}
