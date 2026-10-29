-- Etude de cas TMA DEP/ASIN 2026 - requetes d'impact
-- Export de reference : vendredi 20/11/2026 a 18:00 (heure du Benin, UTC+1)
-- Dialecte : SQLite. Les variantes PostgreSQL/MySQL sont indiquees en commentaire.
--
-- Chargement :
--   sqlite3 rdv.db < base_rendez_vous.sql
--   sqlite3 rdv.db < requetes.sql
-- ou, sans client sqlite3 :
--   php scripts/executer_requetes.php


-- =====================================================================
-- REQUETE 1 - Rendez-vous confirmes a venir pris un samedi ou un dimanche
-- Attendu : 5 lignes.
-- PostgreSQL : EXTRACT(DOW FROM date_heure) IN (0, 6)
-- MySQL      : DAYOFWEEK(date_heure) IN (1, 7)
-- =====================================================================
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure > '2026-11-20 18:00:00'
  AND strftime('%w', date_heure) IN ('0', '6')
ORDER BY date_heure;


-- =====================================================================
-- REQUETE 2 - Creneaux comptant plus de 3 rendez-vous non annules
-- Attendu : 3 lignes (22/10 10:30, 10/11 09:00, 24/11 14:00).
-- Le regroupement porte sur le creneau global, pas sur le service :
-- la capacite est de 3 guichets pour l'ensemble des services.
-- =====================================================================
SELECT date_heure, COUNT(*) AS nb_rendez_vous
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY date_heure
HAVING COUNT(*) > 3
ORDER BY date_heure;


-- =====================================================================
-- REQUETE 3 - Transitions de statut depuis « annule » vers un autre statut
-- Porte sur tout l'historique, sans filtre de date.
-- Attendu : 4 lignes (rv 208, 231, 262, 292).
-- =====================================================================
SELECT h.id AS historique_id,
       h.rendez_vous_id,
       h.ancien_statut,
       h.nouveau_statut,
       h.modifie_le,
       h.modifie_par,
       r.npi,
       r.service,
       r.date_heure,
       r.statut AS statut_actuel
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut <> 'annule'
ORDER BY h.modifie_le;


-- =====================================================================
-- BONUS 1 - Rendez-vous futurs confirmes hors grille.
-- Avant 08:00, apres 15:30, ou minutes non multiples de 30. Attendu : 1 ligne.
-- =====================================================================
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure > '2026-11-20 18:00:00'
  AND (
      (CAST(strftime('%H', date_heure) AS INTEGER) * 60
       + CAST(strftime('%M', date_heure) AS INTEGER)) < 480
      OR (CAST(strftime('%H', date_heure) AS INTEGER) * 60
          + CAST(strftime('%M', date_heure) AS INTEGER)) > 930
      OR ((CAST(strftime('%H', date_heure) AS INTEGER) * 60
           + CAST(strftime('%M', date_heure) AS INTEGER)) - 480) % 30 <> 0
  )
ORDER BY date_heure;


-- =====================================================================
-- BONUS 2 - NPI qui ne comportent pas exactement 10 chiffres.
-- Attendu : 2 lignes (id 459 et 642).
-- PostgreSQL : npi !~ '^[0-9]{10}$'
-- MySQL      : npi NOT REGEXP '^[0-9]{10}$'
-- =====================================================================
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE length(npi) <> 10
   OR npi GLOB '*[^0-9]*'
ORDER BY id;


-- =====================================================================
-- BONUS 3 - Doublons de rendez-vous non annules pour un meme NPI sur un meme creneau.
-- Violation historique de la regle « un seul rendez-vous a venir ».
-- Attendu : 1 ligne (NPI 0536455681, 01/09/2026 09:30, 2 rendez-vous).
-- =====================================================================
SELECT npi, date_heure, COUNT(*) AS nb_rendez_vous
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY npi, date_heure
HAVING COUNT(*) > 1
ORDER BY date_heure;


-- =====================================================================
-- BONUS 4 - Rendez-vous passes encore au statut « confirme ».
-- Attendu : 3 lignes (475, 742, 715), tous des week-ends.
-- =====================================================================
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure < '2026-11-20 18:00:00'
ORDER BY date_heure;
