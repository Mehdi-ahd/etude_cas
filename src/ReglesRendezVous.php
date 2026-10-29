<?php

declare(strict_types=1);

namespace Asin\Tma;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Regles de gestion de la prise de rendez-vous au guichet.
 *
 * Ce module est volontairement independant de toute base de donnees et de tout
 * framework. Il recoit les donnees dont il a besoin (rendez-vous existants,
 * heure courante) et renvoie un Resultat transposable en reponse HTTP.
 *
 * Limite importante : le controle de capacite et le controle « un seul
 * rendez-vous a venir » ne sont pas atomiques. En production, la couche de
 * persistance doit les proteger par une transaction et/ou une contrainte
 * d'unicite.
 */
final class ReglesRendezVous
{
    public const SERVICE_PASSEPORT = 'PASSEPORT';
    public const SERVICE_CNI = 'CNI';
    public const SERVICE_PERMIS = 'PERMIS';

    public const STATUT_CONFIRME = 'confirme';
    public const STATUT_HONORE = 'honore';
    public const STATUT_ABSENT = 'absent';
    public const STATUT_ANNULE = 'annule';

    public const MESSAGE_NPI_INVALIDE = 'Le NPI doit comporter exactement 10 chiffres.';
    public const MESSAGE_SERVICE_INCONNU = 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.';
    public const MESSAGE_DATE_INVALIDE = 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).';
    public const MESSAGE_DATE_PASSEE = 'Impossible de prendre un rendez-vous dans le passé.';
    public const MESSAGE_WEEKEND = 'Le centre est fermé le week-end.';
    public const MESSAGE_CRENEAU_INVALIDE = 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.';
    public const MESSAGE_DEJA_A_VENIR = 'Vous avez déjà un rendez-vous à venir.';
    public const MESSAGE_CRENEAU_COMPLET = 'Ce créneau est complet.';
    public const MESSAGE_NON_ANNULE = 'Ce rendez-vous ne peut plus être annulé.';
    public const MESSAGE_ANNULATION_TARDIVE = 'Annulation impossible moins de 24 h avant le rendez-vous.';
    public const MESSAGE_STATUT_INVALIDE = 'Statut invalide : honore ou absent.';
    public const MESSAGE_DEJA_CLOTURE = 'Ce rendez-vous est déjà clôturé.';

    private const SERVICES = [
        self::SERVICE_PASSEPORT,
        self::SERVICE_CNI,
        self::SERVICE_PERMIS,
    ];

    private const FUSEAU_BENIN = 'Africa/Porto-Novo';
    private const OUVERTURE_MINUTES = 8 * 60;          // 08:00
    private const FERMETURE_MINUTES = 15 * 60 + 30;    // 15:30
    private const PAS_MINUTES = 30;
    private const CAPACITE_CRENEAU = 3;
    private const DELAI_ANNULATION_HEURES = 24;
    private const PAR_PAGE = 10;

    /**
     * Controle et construit une demande de rendez-vous.
     *
     * @param list<RendezVous> $rendezVousUsager  Tous les rendez-vous de l'usager.
     * @param list<RendezVous> $rendezVousCreneau Rendez-vous deja pris sur le creneau demande.
     */
    public function prendreRendezVous(
        DemandeRendezVous $demande,
        DateTimeImmutable $maintenant,
        array $rendezVousUsager = [],
        array $rendezVousCreneau = [],
    ): Resultat {
        // 1. NPI : exactement 10 chiffres ASCII.
        if (preg_match('/^[0-9]{10}$/D', $demande->npi) !== 1) {
            return Resultat::refus(400, self::MESSAGE_NPI_INVALIDE);
        }

        // 2. Service.
        if (!in_array($demande->service, self::SERVICES, true)) {
            return Resultat::refus(400, self::MESSAGE_SERVICE_INCONNU);
        }

        // 3. Date et heure : format strict puis validite calendaire.
        $dateHeure = $this->analyserDateHeure($demande->dateHeure);
        if ($dateHeure === null) {
            return Resultat::refus(400, self::MESSAGE_DATE_INVALIDE);
        }

        // 4. Date passee. Une demande a l'instant present n'est pas « a venir ».
        if ($dateHeure <= $maintenant) {
            return Resultat::refus(409, self::MESSAGE_DATE_PASSEE);
        }

        // 5. Week-end (1 = lundi, 7 = dimanche).
        if ((int) $dateHeure->format('N') >= 6) {
            return Resultat::refus(409, self::MESSAGE_WEEKEND);
        }

        // 6. Grille : 08:00 a 15:30 inclus, toutes les 30 minutes.
        if (!$this->creneauValide($dateHeure)) {
            return Resultat::refus(409, self::MESSAGE_CRENEAU_INVALIDE);
        }

        // 7. Un seul rendez-vous a venir, tous services confondus.
        foreach ($rendezVousUsager as $rendezVous) {
            if ($rendezVous->statut !== self::STATUT_ANNULE && $rendezVous->dateHeure > $maintenant) {
                return Resultat::refus(409, self::MESSAGE_DEJA_A_VENIR);
            }
        }

        // 8. Capacite globale du creneau : 3 usagers, annule libere sa place.
        $occupes = 0;
        foreach ($rendezVousCreneau as $rendezVous) {
            if ($rendezVous->dateHeure == $dateHeure && $rendezVous->statut !== self::STATUT_ANNULE) {
                $occupes++;
            }
        }
        if ($occupes >= self::CAPACITE_CRENEAU) {
            return Resultat::refus(409, self::MESSAGE_CRENEAU_COMPLET);
        }

        $nouveau = new RendezVous(
            null,
            $demande->npi,
            $demande->service,
            $dateHeure,
            self::STATUT_CONFIRME,
            $maintenant,
        );

        return Resultat::succes(201, $nouveau);
    }

    /**
     * Annule un rendez-vous confirme, jusqu'a 24 heures avant incluses.
     */
    public function annulerRendezVous(RendezVous $rendezVous, DateTimeImmutable $maintenant): Resultat
    {
        if ($rendezVous->statut !== self::STATUT_CONFIRME) {
            return Resultat::refus(409, self::MESSAGE_NON_ANNULE);
        }

        $secondesRestantes = $rendezVous->dateHeure->getTimestamp() - $maintenant->getTimestamp();
        if ($secondesRestantes < self::DELAI_ANNULATION_HEURES * 3600) {
            return Resultat::refus(409, self::MESSAGE_ANNULATION_TARDIVE);
        }

        $annule = new RendezVous(
            $rendezVous->id,
            $rendezVous->npi,
            $rendezVous->service,
            $rendezVous->dateHeure,
            self::STATUT_ANNULE,
            $rendezVous->creeLe,
        );

        return Resultat::succes(200, $annule);
    }

    /**
     * Fait passer un rendez-vous confirme a « honore » ou « absent ».
     * Les statuts « honore », « absent » et « annule » sont definitifs.
     */
    public function changerStatut(RendezVous $rendezVous, string $nouveauStatut): Resultat
    {
        if (!in_array($nouveauStatut, [self::STATUT_HONORE, self::STATUT_ABSENT], true)) {
            return Resultat::refus(400, self::MESSAGE_STATUT_INVALIDE);
        }

        if ($rendezVous->statut !== self::STATUT_CONFIRME) {
            return Resultat::refus(409, self::MESSAGE_DEJA_CLOTURE);
        }

        $modifie = new RendezVous(
            $rendezVous->id,
            $rendezVous->npi,
            $rendezVous->service,
            $rendezVous->dateHeure,
            $nouveauStatut,
            $rendezVous->creeLe,
        );

        return Resultat::succes(200, $modifie);
    }

    /**
     * Liste les rendez-vous du plus recent au plus ancien, par pages de 10.
     * Le prochain rendez-vous (seul rendez-vous futur autorise) arrive en tete.
     *
     * @param list<RendezVous> $rendezVousUsager
     */
    public function listerRendezVous(
        array $rendezVousUsager,
        int $page = 1,
        int $parPage = self::PAR_PAGE,
    ): Resultat {
        if ($parPage < 1) {
            $parPage = self::PAR_PAGE;
        }

        $tries = $rendezVousUsager;
        usort($tries, static function (RendezVous $a, RendezVous $b): int {
            $comparaisonDate = $b->dateHeure <=> $a->dateHeure;
            if ($comparaisonDate !== 0) {
                return $comparaisonDate;
            }

            return ($b->id ?? 0) <=> ($a->id ?? 0);
        });

        $total = count($tries);
        $nombreDePages = $total === 0 ? 0 : (int) ceil($total / $parPage);

        if ($page < 1 || $nombreDePages === 0 || $page > $nombreDePages) {
            return Resultat::succes(200, new PageRendezVous($page, $parPage, $total, []));
        }

        $elements = array_slice($tries, ($page - 1) * $parPage, $parPage);

        return Resultat::succes(200, new PageRendezVous($page, $parPage, $total, $elements));
    }

    private function analyserDateHeure(string $valeur): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $valeur) !== 1) {
            return null;
        }

        $fuseau = new DateTimeZone(self::FUSEAU_BENIN);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valeur, $fuseau);

        if ($date === false) {
            return null;
        }

        $erreurs = DateTimeImmutable::getLastErrors();
        if (is_array($erreurs) && ($erreurs['warning_count'] > 0 || $erreurs['error_count'] > 0)) {
            return null;
        }

        // Rejette les dates qui debordent (ex. 2026-02-30 normalise au 2 mars).
        if ($date->format('Y-m-d\TH:i') !== $valeur) {
            return null;
        }

        return $date;
    }

    private function creneauValide(DateTimeImmutable $dateHeure): bool
    {
        $minutes = ((int) $dateHeure->format('G')) * 60 + (int) $dateHeure->format('i');

        return $minutes >= self::OUVERTURE_MINUTES
            && $minutes <= self::FERMETURE_MINUTES
            && (($minutes - self::OUVERTURE_MINUTES) % self::PAS_MINUTES) === 0;
    }
}
