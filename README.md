# Étude de cas TMA — Prise de rendez-vous au guichet

## 1. Contexte et objectif

Ce dépôt contient le rendu de l'étude de cas TMA « prise de rendez-vous au guichet » (ASIN, 2026).

L'application est déjà en production. Le code source n'est pas fourni : le travail part des règles de gestion, des traces du support et de l'export `base_rendez_vous.sql`. La démarche est celle d'un développeur en tierce maintenance applicative :

1. qualifier chaque ticket : anomalie ou comportement conforme ;
2. diagnostiquer la cause probable à partir de la trace ;
3. corriger les règles dans un module indépendant de la base et du framework ;
4. prouver les corrections par des tests automatisés ;
5. mesurer l'impact des anomalies sur les données existantes avec des requêtes SQL ;
6. rendre compte dans ce README.

Le dépôt ne contient volontairement ni API HTTP, ni interface graphique : le sujet demande un module de règles métier, des tests, `requetes.sql` et un README.

## 2. Stack et prérequis

- **PHP 8.2 ou supérieur** (testé avec PHP 8.4.26).
- **Composer 2**.
- **PHPUnit 11.5** (installé par Composer en dépendance de développement).
- Extension **`pdo_sqlite`** pour le script qui exécute les requêtes SQL.
- Aucune base de données externe n'est nécessaire : l'export est chargé en mémoire SQLite.

## 3. Installation et exécution

### 3.1 Installation

```bash
composer install
```

### 3.2 Lancer les tests

```bash
vendor/bin/phpunit
```

ou, avec le script Composer :

```bash
composer test
```

Résultat attendu :

```text
OK (25 tests, 143 assertions)
```

Les 25 tests couvrent les 7 tickets (TMA-101 à TMA-107) et 18 cas limites supplémentaires.

### 3.3 Exécuter les requêtes SQL

Avec le client SQLite, si `sqlite3` est installé :

```bash
sqlite3 rdv.db < base_rendez_vous.sql
sqlite3 rdv.db < requetes.sql
```

Sans client `sqlite3`, le script PHP fourni charge l'export en mémoire et exécute `requetes.sql` :

```bash
php scripts/executer_requetes.php
```

Le script affiche les résultats des trois requêtes demandées et des quatre requêtes bonus.

## 4. Arborescence

```text
asin-etude-de-cas/
├── src/
│   ├── DemandeRendezVous.php      # données brutes d'une demande
│   ├── PageRendezVous.php         # résultat paginé
│   ├── ReglesRendezVous.php       # cœur des règles métier
│   ├── RendezVous.php             # représentation d'un rendez-vous
│   └── Resultat.php               # code HTTP + message + données
├── tests/
│   ├── LimitesTest.php            # cas limites
│   ├── ReglesRendezVousTestCase.php
│   └── TicketsTest.php            # un test par ticket
├── scripts/
│   └── executer_requetes.php      # exécution de requetes.sql sous SQLite
├── base_rendez_vous.sql           # export fourni
├── requetes.sql                   # 3 requêtes demandées + bonus
├── composer.json
├── composer.lock
├── phpunit.xml
└── README.md
```

## 5. Règles de gestion implémentées

Le module `Asin\Tma\ReglesRendezVous` reçoit toutes les données dont il a besoin et ne fait aucune I/O. L'heure courante est toujours passée en paramètre pour rendre les tests déterministes.

### 5.1 Prise de rendez-vous

`prendreRendezVous(DemandeRendezVous $demande, DateTimeImmutable $maintenant, array $rendezVousUsager = [], array $rendezVousCreneau = [])`

Contrôles, dans cet ordre :

1. **NPI** : exactement 10 chiffres ASCII (`^[0-9]{10}$`), sinon `400` avec `Le NPI doit comporter exactement 10 chiffres.`
2. **Service** : `PASSEPORT`, `CNI` ou `PERMIS`, sinon `400`.
3. **Date et heure** : format strict `AAAA-MM-JJTHH:MM`, date calendaire valide, sinon `400`.
4. **Date passée** : refus si `dateHeure <= maintenant`, avec `409`.
5. **Week-end** : refus si samedi ou dimanche, avec `409`.
6. **Grille** : de 08:00 à 15:30 inclus, toutes les 30 minutes, sinon `409`.
7. **Un seul rendez-vous à venir** : refus si l'usager a déjà un rendez-vous non annulé dont la date est future, avec `409`.
8. **Capacité** : refus si le créneau compte déjà 3 rendez-vous non annulés, **tous services confondus**, avec `409`.
9. Succès : `201` et un `RendezVous` au statut `confirme`.

### 5.2 Annulation

`annulerRendezVous(RendezVous $rendezVous, DateTimeImmutable $maintenant)`

- Le rendez-vous doit être au statut `confirme`, sinon `409` avec `Ce rendez-vous ne peut plus être annulé.`
- L'annulation est acceptée si `dateHeure - maintenant >= 24 heures`. La borne est **incluse** : une annulation à exactement 24 h est autorisée.
- En dessous de 24 h : `409` avec `Annulation impossible moins de 24 h avant le rendez-vous.`
- Succès : `200` et un nouveau `RendezVous` au statut `annule`.

### 5.3 Changement de statut

`changerStatut(RendezVous $rendezVous, string $nouveauStatut)`

- Le statut demandé doit être `honore` ou `absent`, sinon `400` avec `Statut invalide : honore ou absent.`
- Le rendez-vous doit être au statut `confirme`, sinon `409` avec `Ce rendez-vous est déjà clôturé.`
- Les statuts `honore`, `absent` et `annule` sont définitifs.
- Succès : `200` et un nouveau `RendezVous` avec le statut demandé.

### 5.4 Listing et pagination

`listerRendezVous(array $rendezVousUsager, int $page = 1, int $parPage = 10)`

- Tri par `dateHeure` décroissante, puis par `id` décroissant en cas d'égalité.
- Le prochain rendez-vous (seul rendez-vous futur autorisé) arrive donc en tête.
- Pagination de 10 éléments par défaut.
- Une page hors bornes (0, 99, etc.) renvoie une liste vide, mais conserve le total.
- Le résultat contient `page`, `parPage`, `total` et `rendezVous`.

### 5.5 Exemple d'utilisation

```php
use Asin\Tma\DemandeRendezVous;
use Asin\Tma\ReglesRendezVous;
use DateTimeImmutable;
use DateTimeZone;

$regles = new ReglesRendezVous();

$resultat = $regles->prendreRendezVous(
    new DemandeRendezVous('0614253647', 'PASSEPORT', '2026-11-23T09:00'),
    new DateTimeImmutable('2026-11-01 12:00', new DateTimeZone('Africa/Porto-Novo')),
);

if (!$resultat->ok) {
    http_response_code($resultat->code);
    echo $resultat->message;
}
```

Les dates sont exprimées en heure du Bénin (`Africa/Porto-Novo`, UTC+1), sans changement d'heure.

## 6. Analyse des tickets

### 6.1 Synthèse

| Ticket | Qualification | Règle de référence | Cause probable | Correction | Test |
|---|---|---|---|---|---|
| TMA-101 | Anomalie | Centre ouvert du lundi au vendredi | Contrôle du week-end absent ou mal indexé | Refuser samedi/dimanche avec `409` | T1 |
| TMA-102 | Anomalie | 3 usagers maximum par créneau ; annulé libère sa place | Capacité probablement comptée par service, ou contrôle absent/non atomique | Compter le créneau global, tous services, hors `annule` | T2 |
| TMA-103 | Anomalie | NPI = exactement 10 chiffres | Validation absente ou conversion `int()` non protégée → erreur 500 | Valider le NPI avant tout traitement, retour `400` | T3 |
| TMA-104 | Anomalie | Annulation possible jusqu'à 24 h avant | Calcul sur `delta.days` ou seuil 48 h au lieu de la durée exacte | Comparer la durée exacte, borne 24 h incluse | T4 |
| TMA-105 | Anomalie | `confirme` → `honore`/`absent`/`annule`, statuts définitifs | Aucun contrôle de transition | Refuser si le statut courant n'est pas `confirme` | T5 |
| TMA-106 | Anomalie | Tri décroissant, prochain rendez-vous en tête, 10 par page | Pas de `ORDER BY`, ou pagination avant tri | Trier `dateHeure DESC`, puis paginer | T6 |
| TMA-107 | **Conforme** | Un seul rendez-vous à venir, tous services confondus | La règle est correctement appliquée | Ne pas modifier ; test de non-régression | T7 |

### 6.2 TMA-101 — rendez-vous un samedi

**Signalement.** L'usager a pris rendez-vous le samedi 14/11/2026 à 10:00 ; le centre était fermé.

**Trace.**

```text
POST /api/rendez-vous
{"npi":"0614253647","service":"PASSEPORT","dateHeure":"2026-11-14T10:00"}
-> 201 {"id":742,...,"dateHeure":"14/11/2026 10:00","statut":"confirme"}
```

**Diagnostic.** Le 14/11/2026 est un samedi. L'application a accepté la demande, donc le contrôle du jour de la semaine est absent, ou mal indexé (par exemple un test sur le mauvais jour, ou un test qui ne couvre que le dimanche). Ce n'est pas un problème de format de date : la date est valide et le créneau 10:00 est dans la grille.

**Correction.** Dans `prendreRendezVous`, le contrôle utilise `format('N') >= 6` : `N` vaut 1 pour lundi et 7 pour dimanche. Samedi et dimanche renvoient `409` avec `Le centre est fermé le week-end.`

**Test.** T1 rejoue la demande du 14/11/2026 à 10:00 et attend `409` avec le message exact.

**Impact.** L'export contient 8 rendez-vous le week-end, tous au statut `confirme` : 5 sont encore futurs, 3 sont passés. La requête 1 liste les 5 rendez-vous futurs à traiter.

### 6.3 TMA-102 — quatre usagers sur le même créneau

**Signalement.** Le mardi 10/11/2026 à 09:00, quatre usagers avaient rendez-vous sur le même créneau ; le centre n'a que trois guichets.

**Trace.** Les rendez-vous 632, 644, 681 et 697 occupent le créneau. Le 697 a été réservé après les trois autres, et l'API a répondu `201`.

**Diagnostic.** La capacité est de 3 usagers pour le créneau complet, pas 3 par service. Or les quatre rendez-vous sont répartis ainsi :

| Rendez-vous | Service |
|---|---|
| 632 | PASSEPORT |
| 644 | CNI |
| 681 | PERMIS |
| 697 | PERMIS |

La trace indique `service: CNI` pour la quatrième requête, tandis que l'export enregistre le rendez-vous 697 en PERMIS. Le point important est que la capacité doit être comptée globalement, quel que soit le service.

Un contrôle par service ne verrait jamais 3 rendez-vous sur un même service (au plus 2 dans l'export) et laisserait donc passer le 4e. C'est la cause la plus probable. Une autre possibilité est l'absence de contrôle, ou un contrôle non atomique qui laisse deux requêtes simultanées dépasser la capacité. Les trois créneaux en dépassement de l'export ont d'ailleurs tous au plus 2 rendez-vous par service, ce qui est cohérent avec un comptage par service.

**Correction.** Le module compte tous les rendez-vous du créneau dont le statut n'est pas `annule`, sans distinguer le service. Si le total est supérieur ou égal à 3, il renvoie `409` avec `Ce créneau est complet.` Un rendez-vous `annule` libère bien sa place.

**Test.** T2 place trois rendez-vous actifs sur le 10/11/2026 à 09:00, puis demande un quatrième : `409`. Un test complémentaire vérifie qu'avec 2 actifs et 1 annulé, la demande passe.

**Impact.** La requête 2 trouve 3 créneaux en dépassement : 22/10/2026 10:30, 10/11/2026 09:00 et 24/11/2026 14:00. Ce dernier est futur : il faut prévenir les usagers ou déplacer un rendez-vous.

**Limite.** Le contrôle du module n'est pas atomique. En production, il faut une transaction et une contrainte base (ou un verrou) pour empêcher deux réservations simultanées de dépasser la capacité.

### 6.4 TMA-103 — NPI contenant une lettre

**Signalement.** Quand un usager tape une lettre dans son NPI, l'écran affiche « Erreur interne du serveur ».

**Trace.**

```text
POST /api/rendez-vous
{"npi":"06142A3647","service":"CNI","dateHeure":"2026-11-23T09:00"}
-> 500 {"erreur":"Erreur interne du serveur"}
```

**Diagnostic.** Le NPI contient 10 caractères, mais pas 10 chiffres. L'application ne valide pas le format avant de traiter la demande : elle tente probablement une conversion numérique (`int`, `filter_var`, etc.) ou une opération sur un NPI non numérique, et l'exception remonte en 500. Le problème n'est pas seulement l'absence de message : c'est un contrôle de saisie manquant, qui transforme une erreur utilisateur en erreur serveur.

**Correction.** Le module applique `preg_match('/^[0-9]{10}$/D', $npi)` avant tout autre traitement. Un NPI de 9 ou 11 chiffres, ou contenant une lettre, renvoie `400` avec `Le NPI doit comporter exactement 10 chiffres.` Aucune exception n'est levée pour une saisie invalide.

**Test.** T3 rejoue `06142A3647` et attend `400` avec le message exact. Des tests complémentaires couvrent 9 et 11 chiffres.

**Impact.** L'export contient 2 NPI invalides : `01234567890` (11 chiffres) et `061425364711` (12 chiffres). La requête bonus 2 les liste.

### 6.5 TMA-104 — annulation refusée à 40 heures

**Signalement.** Le mardi 17/11/2026 à 18:00, l'usager a voulu annuler son rendez-vous du jeudi 19/11/2026 à 10:00. L'application a refusé avec « Annulation impossible moins de 24 h avant ».

**Trace.**

```text
POST /api/rendez-vous/709/annulation
-> 409 {"erreur":"Annulation impossible moins de 24 h avant le rendez-vous."}
```

**Diagnostic.** Entre le 17/11 à 18:00 et le 19/11 à 10:00, il reste **40 heures**, donc bien plus que 24 heures. Le refus est incorrect. La cause probable est un calcul sur les jours entiers : `dateHeure->diff($maintenant)->days` vaut 1 pour 40 heures (1 jour et 16 heures). Un test du type `days < 2`, ou un seuil de 48 heures, refuse alors que la règle demande 24 heures. Une comparaison sur les dates calendaires seules, ou sur les heures seules, produirait le même type d'erreur de borne.

**Correction.** Le module calcule la différence exacte en secondes :

```php
$secondesRestantes = $rendezVous->dateHeure->getTimestamp() - $maintenant->getTimestamp();
if ($secondesRestantes < 24 * 3600) {
    // refus
}
```

La borne est inclusive : 24 h exactement est autorisé, 23 h 59 est refusé.

**Test.** T4 rejoue le cas du 17/11 à 18:00 et vérifie que l'annulation est acceptée, avec 40 heures restantes. Des tests complémentaires couvrent exactement 24 h et 23 h 59.

**Note sur l'export.** Le rendez-vous 709 est aujourd'hui au statut `absent` : l'annulation ayant été refusée, l'agent a probablement clôturé le rendez-vous plus tard. Le test rejoue l'état au moment du ticket, c'est-à-dire `confirme`.

### 6.6 TMA-105 — annulé puis honoré

**Signalement.** Un agent a cliqué par erreur sur « Honoré » pour un rendez-vous que l'usager avait annulé. L'application a accepté, et le rendez-vous apparaît maintenant comme honoré.

**Trace.**

```text
PATCH /api/rendez-vous/292/statut  {"statut":"honore"}
-> 200 {"id":292,...,"statut":"honore"}
```

**Diagnostic.** Le rendez-vous 292 était au statut `annule`, qui est définitif. L'application ne contrôle pas la transition : elle accepte n'importe quel statut demandé. La règle dit qu'un rendez-vous `confirme` devient `honore`, `absent` ou `annule`, et que ces trois statuts sont définitifs. Une transition depuis `annule`, `honore` ou `absent` doit donc être refusée.

**Correction.** Le module vérifie d'abord que le statut demandé est `honore` ou `absent` (`400` sinon), puis que le statut courant est `confirme` (`409` avec `Ce rendez-vous est déjà clôturé.` sinon). Aucune réouverture n'est possible.

**Test.** T5 rejoue `annule -> honore` et attend `409`. Des tests complémentaires couvrent `honore -> absent`, `absent -> honore` et une demande de statut `confirme`.

**Impact.** La requête 3 trouve 4 transitions `annule` vers un autre statut : les rendez-vous 208, 231, 262 et 292. Le 292 est celui du ticket ; les trois autres sont des anomalies non signalées.

### 6.7 TMA-106 — prochain rendez-vous absent de la première page

**Signalement.** Dans « Mes rendez-vous », le prochain rendez-vous du 02/12/2026 n'est pas en haut de la liste, et n'est même pas sur la première page.

**Trace.** Pour l'usager `0203040506`, l'API renvoie `total: 13`, `parPage: 10`, et les dates suivantes dans l'ordre :

```text
28/07/2026 09:30, 25/09/2026 11:00, 21/04/2026 09:00
19/05/2026 15:00, 16/06/2026 13:30, 12/02/2026 10:00
11/08/2026 14:00, 08/05/2026 11:30, 07/07/2026 10:30
```

**Diagnostic.** Le rendez-vous du 02/12/2026 n'apparaît pas, alors qu'il est le plus récent. L'ordre renvoyé n'est ni chronologique, ni l'ordre des identifiants, ni l'ordre de création. Le plus probable est l'absence de `ORDER BY` : la base renvoie les lignes dans un ordre arbitraire, puis l'application pagine ce résultat. Une pagination appliquée avant le tri produit le même symptôme.

**Correction.** Le module trie d'abord tous les rendez-vous par `dateHeure` décroissante (puis par `id` décroissant en cas d'égalité), et pagine ensuite. La page 1 correcte est :

```text
02/12/2026 08:30, 25/09/2026 11:00, 11/08/2026 14:00, 28/07/2026 09:30,
07/07/2026 10:30, 16/06/2026 13:30, 02/06/2026 08:00, 19/05/2026 15:00,
08/05/2026 11:30, 21/04/2026 09:00
```

La page 2 contient `03/03/2026 14:30`, `12/02/2026 10:00`, `05/01/2026 08:30`. La page 3 est vide.

**Test.** T6 utilise les 13 rendez-vous de l'usager `0203040506` et vérifie l'ordre, le total, les pages 1, 2 et 3. Le test échoue avec l'ordre de la trace, puisque `28/07/2026` n'est plus en tête.

### 6.8 TMA-107 — second rendez-vous pour un autre service

**Signalement.** L'usager a un rendez-vous pour son passeport la semaine prochaine et ne peut pas en prendre un autre pour son permis.

**Trace.**

```text
POST /api/rendez-vous
{"npi":"0102030405","service":"PERMIS","dateHeure":"2026-11-27T09:00"}
-> 409 {"erreur":"Vous avez déjà un rendez-vous à venir."}
```

**Qualification : comportement conforme.** La règle dit qu'un usager ne peut avoir qu'un seul rendez-vous à venir, tous services confondus. L'API applique correctement cette règle : le refus en `409` est attendu. Il ne faut donc pas modifier ce comportement.

**Test.** T7 est un test de non-régression : il rejoue la demande et attend `409` avec `Vous avez déjà un rendez-vous à venir.` Il échouerait si quelqu'un supprimait ou affaiblissait la règle.

**Anomalie historique bonus.** L'export contient un doublon actif pour le NPI `0536455681` sur le créneau du 01/09/2026 à 09:30 : deux rendez-vous, l'un PERMIS, l'autre PASSEPORT, tous deux `honore`. Le second a été créé le 30/08 alors que le premier existait déjà : la règle n'a donc pas toujours été appliquée. Le module refuserait ce second rendez-vous. La requête bonus 3 liste ce doublon.

## 7. Tests automatisés

### 7.1 Les sept tickets

| Test | Heure courante | Scénario | Attendu |
|---|---|---|---|
| T1 | 01/11/2026 12:00 | RDV samedi 14/11/2026 10:00 | `409` centre fermé |
| T2 | 01/11/2026 12:00 | 3 rendez-vous actifs le 10/11 à 09:00, puis un 4e | `409` créneau complet |
| T3 | 01/11/2026 12:00 | NPI `06142A3647` | `400` NPI invalide, pas `500` |
| T4 | 17/11/2026 18:00 | Annulation d'un RDV le 19/11 à 10:00 | `200`, 40 h restantes |
| T5 | — | `annule` → `honore` | `409` déjà clôturé |
| T6 | — | 13 rendez-vous, page 1 | 10 éléments, `02/12/2026` en tête, total 13 |
| T7 | 01/11/2026 12:00 | RDV futur le 26/11, demande le 27/11 autre service | `409` déjà un rendez-vous à venir |

Chaque test vérifie le code et le message exact. Les tests T1 à T6 échouent avec le comportement décrit dans les traces ; T7 est un test de non-régression.

### 7.2 Cas limites

`LimitesTest.php` couvre notamment :

- NPI de 9 et 11 chiffres ;
- date passée, et date égale à l'instant présent ;
- 07:30 refusé, 08:00 accepté, 15:30 accepté, 16:00 refusé, 08:15 refusé ;
- créneau plein, puis libéré par une annulation ;
- annulation à exactement 24 h (acceptée) et à 23 h 59 (refusée) ;
- annulation d'un rendez-vous `honore`, `absent` ou `annule` (refusée) ;
- statut demandé `confirme` (400) ;
- transitions depuis `honore`, `absent`, `annule` (409) ;
- changement `confirme -> honore` et `confirme -> absent` (200) ;
- pagination page 0, page 99, liste vide ;
- tri par date puis par identifiant ;
- service inconnu ;
- formats de date invalides, dont `2026-02-30` ;
- dimanche ;
- doublon même NPI / même créneau.

### 7.3 Résultat

```text
OK (25 tests, 143 assertions)
```

## 8. Impact en base

L'export est daté du **vendredi 20/11/2026 à 18:00** (heure du Bénin). Toutes les requêtes utilisent cette référence.

Chiffres clés de l'export : **889 rendez-vous**, **757 lignes d'historique**, **133 rendez-vous futurs** (tous `confirme`), **8 rendez-vous le week-end** et **3 créneaux en dépassement de capacité**.

### 8.1 Requête 1 — rendez-vous confirmés à venir le week-end

```sql
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure > '2026-11-20 18:00:00'
  AND strftime('%w', date_heure) IN ('0', '6')
ORDER BY date_heure;
```

Résultat : **5 lignes**.

| id | npi | service | date_heure |
|---|---|---|---|
| 680 | 0669147625 | PASSEPORT | 2026-11-21 08:30 |
| 730 | 0679617916 | CNI | 2026-11-28 09:00 |
| 834 | 0610588823 | CNI | 2026-12-05 10:00 |
| 857 | 0671987446 | PERMIS | 2026-12-05 10:00 |
| 874 | 0648953073 | PASSEPORT | 2026-12-12 11:00 |

Le rendez-vous 742 du ticket TMA-101 n'apparaît pas : il est daté du 14/11/2026, donc déjà passé à la date de l'export. La requête porte bien sur les rendez-vous **à venir**. Au total, l'export contient 8 rendez-vous le week-end, tous `confirme` : 5 futurs (ci-dessus) et 3 passés (475, 742, 715).

### 8.2 Requête 2 — créneaux à plus de 3 rendez-vous non annulés

```sql
SELECT date_heure, COUNT(*) AS nb_rendez_vous
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY date_heure
HAVING COUNT(*) > 3
ORDER BY date_heure;
```

Résultat : **3 lignes**.

| date_heure | nb_rendez_vous |
|---|---|
| 2026-10-22 10:30 | 4 |
| 2026-11-10 09:00 | 4 |
| 2026-11-24 14:00 | 4 |

Le créneau du 24/11/2026 à 14:00 est futur : c'est le seul qui permet encore d'agir avant la date. Les deux autres sont passés. Le regroupement porte sur `date_heure` seule : si on groupait aussi par service, le créneau du 10/11 ne serait pas détecté, car les quatre rendez-vous sont répartis sur plusieurs services.

### 8.3 Requête 3 — rendez-vous passés de `annule` à un autre statut

```sql
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
  AND r.date_heure < '2026-11-20 18:00:00'
ORDER BY h.modifie_le;
```

Résultat : **4 lignes**.

| historique | rendez_vous | transition | modifié le | agent | npi | date du RDV | statut actuel |
|---|---|---|---|---|---|---|---|
| 346 | 208 | annule → honore | 2026-10-07 10:20 | AG-01 | 0384914669 | 2026-10-07 10:00 | honore |
| 430 | 231 | annule → honore | 2026-10-15 10:26 | AG-02 | 0370202195 | 2026-10-15 10:00 | honore |
| 547 | 262 | annule → absent | 2026-10-29 10:21 | AG-03 | 0317656053 | 2026-10-29 10:00 | absent |
| 676 | 292 | annule → honore | 2026-11-12 10:13 | AG-04 | 0330677555 | 2026-11-12 10:00 | honore |

Le rendez-vous 292 est celui du ticket TMA-105. Les trois autres transitions n'étaient pas signalées.

### 8.4 Requêtes bonus

| Requête | Objectif | Résultat |
|---|---|---|
| Bonus 1 | Rendez-vous futurs confirmés hors grille | 1 ligne : id 842, 2026-12-08 16:00 |
| Bonus 2 | NPI qui ne font pas exactement 10 chiffres | 2 lignes : id 459 (`01234567890`), id 642 (`061425364711`) |
| Bonus 3 | Doublons actifs même NPI / même créneau | 1 ligne : `0536455681`, 2026-09-01 09:30, 2 rendez-vous |
| Bonus 4 | Rendez-vous passés encore `confirme` | 3 lignes : 475, 742, 715, tous des week-ends |

Ces anomalies sont expliquées et couvertes par le module :

- le créneau de 16:00 est refusé par la règle de grille (08:00–15:30, pas de 30 minutes) ;
- les NPI de 11 et 12 chiffres sont refusés par la règle des 10 chiffres ;
- le doublon est refusé par la règle « un seul rendez-vous à venir » ;
- les rendez-vous passés encore `confirme` peuvent être clôturés par un agent (`confirme -> honore` ou `confirme -> absent`), mais le module ne les clôture pas automatiquement.

## 9. Limites et améliorations

- **Pas de couche HTTP ni d'interface.** Le module renvoie un `Resultat` avec un code et un message ; c'est à l'API de les transmettre. Le sujet ne demande pas de routes.
- **Pas de persistance.** Le module ne crée pas d'identifiant et n'écrit pas l'historique. L'appelant doit enregistrer le rendez-vous et la ligne `historique_statuts` avec `modifie_par`.
- **Contrôles non atomiques.** La capacité et l'unicité du rendez-vous à venir sont vérifiées en mémoire. En production, il faut une transaction et une contrainte base pour éviter les courses entre deux requêtes simultanées.
- **Pas de gestion des jours fériés.** La règle fournie ne parle que des samedis et dimanches. Un jour férié n'est pas traité.
- **Heure du Bénin uniquement.** Les dates sont naïves et interprétées en `Africa/Porto-Novo` (UTC+1, sans changement d'heure). Aucune conversion de fuseau n'est faite.
- **Dialecte SQL.** Les requêtes sont écrites pour SQLite, avec les variantes PostgreSQL/MySQL en commentaire. L'export est compatible avec les trois, mais les fonctions de date diffèrent.
- **Instantané de l'export.** Les résultats dépendent de la référence du 20/11/2026 à 18:00. Après cette date, les rendez-vous futurs changent.
- **Anomalies historiques non corrigées en base.** Le module empêche les nouvelles violations, mais ne modifie pas les lignes existantes. Les requêtes d'impact servent à les identifier.
- **Pas de notification.** Les requêtes listent les usagers à prévenir ; l'envoi des messages n'est pas dans le périmètre.

## 10. Démarche et historique Git

Le dépôt a été construit par étapes, avec un commit après chaque modification majeure :

1. `chore: initialisation du projet PHP et de l'export SQL`
2. `feat: module de regles metier (prise, annulation, statut, pagination)`
3. `test: scenarios TMA-101 a TMA-107 et cas limites`
4. `feat(sql): requetes d'impact et script d'execution SQLite`
5. `docs: compte rendu README et resultats`

Pour rejouer le travail :

```bash
composer install
vendor/bin/phpunit
php scripts/executer_requetes.php
```
