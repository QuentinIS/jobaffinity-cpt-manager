# Publier vos offres JobAffinity dans un Custom Post Type dédié (WordPress)

Ce guide complète la documentation officielle [« Comment publier vos offres sur votre site WordPress ? »](https://help.jobaffinity.fr/fr/articles/11561675-comment-publier-vos-offres-sur-votre-site-wordpress) de JobAffinity.

Par défaut, JobAffinity publie vos offres comme des **articles WordPress classiques** (dans la section *Articles*). Avec le plugin **JobAffinity CPT Manager**, les offres sont routées vers un **Custom Post Type dédié** (ex. `offer`), ce qui permet :

- de ne pas mélanger les offres avec le blog de l'entreprise,
- d'avoir un menu dédié dans l'admin (*Offres*),
- d'exposer proprement les offres dans l'API REST,
- de conserver l'intégralité des champs personnalisés envoyés par JobAffinity.

> **À noter** : le canal recommandé est l'**API REST** de WordPress. Dans *Admin > Publications*, le champ **Type de contenu** de votre source JobAffinity accepte la clé du CPT : les offres arrivent alors directement dedans, sans interception. Les options d'interception (section 4) ne servent qu'aux flux qui visent encore le post type `post`, XML-RPC compris.

---

## Prérequis

- WordPress 5.6 ou supérieur
- Un compte administrateur sur votre site WordPress
- Un compte JobAffinity avec accès à *Admin > Publications*

---

## 1. Installer et activer le plugin

1. Dans l'admin WordPress, allez dans **Extensions > Ajouter une extension**.
2. Recherchez **JobAffinity CPT Manager**, ou téléversez l'archive si vous l'avez téléchargée.
3. Cliquez sur **Installer maintenant**, puis **Activer**.

Un bandeau apparaît en haut de l'admin : « JobAffinity CPT Manager : please configure the custom post type key. »

---

## 2. Configurer le CPT

Allez dans **Réglages > JobAffinity CPT Manager** et remplissez :

| Champ | Valeur recommandée | Explication |
|---|---|---|
| **Clé du type de contenu** (*Post type key*) | `offer` | Identifiant interne et slug d'URL (minuscules, chiffres, tirets, 20 caractères max) |
| **Libellé singulier** (*Singular label*) | `Offre` | Affiché dans l'admin (« Ajouter une nouvelle Offre ») |
| **Libellé pluriel** (*Plural label*) | `Offres` | Nom du menu dans la sidebar admin |
| **Icône du menu** (*Menu icon*) | `dashicons-businessperson` | Une [Dashicon WordPress](https://developer.wordpress.org/resource/dashicons/) |

Les libellés sont donnés en français suivis de l'intitulé d'origine : la traduction française est servie par translate.wordpress.org et peut ne pas encore être active sur votre installation.

Cliquez sur **Enregistrer les réglages**. Un nouveau menu **Offres** apparaît dans la sidebar.

> **Important** : définissez la clé avant la première publication. La changer après coup laisse les anciens contenus attachés à l'ancien post type.

---

## 3. Créer l'utilisateur JobAffinity

Comme indiqué dans la doc JobAffinity :

1. Allez dans **Utilisateurs > Ajouter**.
2. Créez un utilisateur `jobaffinity` avec le rôle **Auteur** (ou Éditeur).
3. Ouvrez sa fiche, section **Mots de passe d'application**, et générez-en un nommé `JobAffinity`. Copiez-le immédiatement : WordPress ne le réaffiche jamais.

C'est ce mot de passe d'application que vous saisirez côté JobAffinity, pas le mot de passe du compte. Il est propre à cette intégration et se révoque en un clic depuis la même page.

Le plugin donne automatiquement à ce rôle l'accès complet au CPT (les capabilities sont héritées de `post`, donc admin / éditeur / auteur ont les mêmes droits sur les offres que sur les articles).

---

## 4. Router les publications JobAffinity vers le CPT

### Le chemin normal — nommer le type de contenu dans la source

Dans JobAffinity, **Admin > Publications**, éditez votre source WordPress :

- **Mode de publication** : API REST
- **Identifiant** / **Mot de passe** : le compte `jobaffinity` et son mot de passe d'application (section 3)
- **Type de contenu** : la clé du CPT, `offer` par exemple

JobAffinity publie alors directement sur `/wp-json/wp/v2/offer`. Aucune interception n'est nécessaire, et les champs libres `custom_*` passent par l'objet `custom_fields` du plugin (section 6).

### Le repli — interception d'un flux qui vise encore `post`

Si le flux ne peut pas nommer le type de contenu, le plugin intègre un **détecteur automatique**. Dans **Réglages > JobAffinity CPT Manager**, cochez selon le cas :

> ☑ **Interception API REST** (*REST API interception*) — pour un flux qui arrive sur `/wp-json/wp/v2/posts`
> ☑ **Interception XML-RPC** (*XML-RPC interception*) — pour une connexion encore en XML-RPC

Comment ça marche concrètement :

1. JobAffinity envoie sa publication vers votre WordPress, en visant le post type `post`.
2. Le plugin inspecte les champs personnalisés de la requête.
3. Si la meta `job_id` est présente (signature JobAffinity), le `post_type` est changé de `post` vers votre CPT (`offer`) avant l'insertion en base.
4. Les articles classiques du site (blog, actualités) ne sont **pas** affectés.

L'interception REST ne s'applique qu'à la **création** d'une offre, jamais à la mise à jour d'un contenu existant.

### Pourquoi la détection par `job_id` ?

C'est la seule meta envoyée systématiquement par JobAffinity sur toutes les offres (contrairement à `job_location` ou `job_link` qui peuvent être vides dans certains cas). Ça garantit qu'aucune publication non-JobAffinity ne soit redirigée par erreur.

### XML-RPC, mode d'héritage

XML-RPC reste pris en charge pour les connexions antérieures à l'arrivée de REST, mais il exige le **vrai mot de passe** du compte, ne publie que sur `post`, et `xmlrpc.php` est bloqué par défaut par la plupart des extensions de sécurité. Pour toute nouvelle configuration, utilisez l'API REST.

---

## 5. Champs personnalisés transmis par JobAffinity

JobAffinity envoie les meta suivantes, toutes conservées par le plugin et accessibles en lecture/écriture via l'API REST.

### Champs déclarés (utilisables dans l'objet `meta`)

Ces 22 clés sont **déclarées** par le plugin via `register_post_meta()`. C'est ce qui les rend utilisables dans l'objet `meta` standard de l'API REST.

Elles forment un **socle obligatoire** : elles sont toujours déclarées et ne peuvent pas être retirées depuis l'interface. Dans *Réglages → JobAffinity CPT Manager*, le champ **Champs supplémentaires** permet d'**ajouter** d'autres clés par-dessus ce socle, jamais de le remplacer.

| Clé | Description | Exemple |
|---|---|---|
| `job_id` | Identifiant unique JobAffinity | `1023736` |
| `job_reference` | Référence de l'offre | `REF-2026-001` |
| `job_organisation` | Nom de votre structure | `Picard Surgelés` |
| `job_client_remote_id` | ID client dans JobAffinity | `643` |
| `job_client` | Nom du client | `136 - PAROISSE` |
| `job_entity` | Entité qui recrute | `Picard` |
| `job_location` | Localisation du poste | `Versailles` |
| `job_address` | Adresse du poste | `76 RUE DE LA PAROISSE` |
| `job_postalcode` | Code postal | `78000` |
| `job_town` | Ville | `Versailles` |
| `job_country` | Code pays ISO | `FR` |
| `job_latitude` | Latitude GPS | `48.8036` |
| `job_longitude` | Longitude GPS | `2.1203` |
| `job_link` | URL du formulaire de candidature | `https://jobaffinity.fr/apply/...` |
| `job_contract_type` | Type de contrat | `CDI` |
| `job_contract_length` | Durée du contrat | `6` |
| `job_contract_length_unit` | Unité de durée | `mois` |
| `apply_url` | URL de candidature | `https://jobaffinity.fr/apply/...` |
| `job_salary_min` | Salaire minimum | `28000` |
| `job_salary_max` | Salaire maximum | `32000` |
| `job_salary_currency` | Devise | `EUR` |
| `job_salary_period` | Périodicité | `year` |

**Toutes ces clés sont typées `string`**, y compris les salaires et les coordonnées GPS. C'est volontaire : JobAffinity envoie tout en chaîne, et déclarer `number` sur `job_salary_min` ferait rejeter l'offre entière avec une erreur `rest_invalid_type`.

### Champs libres (`custom_*`)

Les champs préfixés `custom_` sont configurés côté JobAffinity et diffèrent d'un client à l'autre :

| Clé | Description | Exemple |
|---|---|---|
| `custom_filiere_metier` | Filière métier (perso) | `Magasins` |
| `custom_regions` | Région interne (perso) | `YVELINES SUD` |
| `custom_temps_de_travail` | Temps de travail (perso) | `Temps plein` |

Ils ne sont **pas** déclarés par défaut, donc **pas** utilisables dans `meta`. Deux façons de les transmettre :

1. via l'objet `custom_fields` du plugin (aucune configuration, voir §6) ;
2. en les ajoutant à la liste des clés déclarées dans *Réglages → JobAffinity CPT Manager*, si vous préférez tout passer par `meta`.

---

## 6. Accéder aux offres via l'API REST

Une fois le CPT configuré sous la clé `offer`, l'endpoint standard WordPress est disponible :

```
GET  /wp-json/wp/v2/offer              Liste des offres
GET  /wp-json/wp/v2/offer/{id}         Détail d'une offre
POST /wp-json/wp/v2/offer              Création (authentification requise)
POST /wp-json/wp/v2/offer/{id}         Mise à jour (authentification requise)
```

### Deux canaux d'écriture

| | `meta` | `custom_fields` |
|---|---|---|
| Standard WordPress | oui | non (spécifique au plugin) |
| Clés acceptées | uniquement les clés déclarées | n'importe quelle clé |
| Valeurs multiples | non (`single => true`) | oui (tableau indexé) |
| Suppression par `null` | oui | oui |
| Disponible sur `/wp/v2/posts` | oui (si l'option est cochée) | non |

**Utilisez `meta`** pour les clés déclarées : c'est le canal standard, celui qu'attendent les clients REST génériques. **Utilisez `custom_fields`** pour tout le reste : clés libres `custom_*`, valeurs multiples.

Si la même clé arrive par les deux canaux dans une même requête, c'est la valeur de `custom_fields` qui est conservée (WordPress écrit `meta` en premier, puis les champs additionnels du plugin).

### Base de route personnalisable

Par défaut, la route REST reprend la clé du CPT. Le réglage **Base de route API REST** permet de les découpler : un CPT nommé `offer-intern` peut être exposé sur `/wp-json/wp/v2/offer`. Les URLs publiques du site continuent, elles, de suivre la clé du CPT.

### Lecture des champs personnalisés

Chaque réponse inclut l'objet `meta` (clés déclarées) et l'objet `custom_fields` qui rassemble toutes les meta non protégées :

```json
{
  "id": 1234,
  "title": { "rendered": "Vendeur H/F - Picard Versailles" },
  "content": { "rendered": "<p>Description...</p>" },
  "meta": {
    "job_id": "1023736",
    "job_client": "136 - PAROISSE",
    "job_contract_type": "CDI",
    "job_link": "https://jobaffinity.fr/apply/..."
  },
  "custom_fields": {
    "job_id": "1023736",
    "job_client": "136 - PAROISSE",
    "job_contract_type": "CDI",
    "job_link": "https://jobaffinity.fr/apply/...",
    "custom_regions": "YVELINES SUD"
  }
}
```

### Création d'une offre via l'API REST

```bash
curl -X POST https://votre-site.fr/wp-json/wp/v2/offer \
  -u "jobaffinity:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Vendeur H/F - Picard Versailles",
    "content": "<p>Description du poste...</p>",
    "status": "publish",
    "meta": {
      "job_id": "1023736",
      "job_client": "136 - PAROISSE",
      "job_client_remote_id": "643",
      "job_contract_type": "CDI",
      "job_address": "76 RUE DE LA PAROISSE",
      "job_country": "FR",
      "job_entity": "Picard",
      "job_latitude": "48.8036",
      "job_salary_min": "28000",
      "job_link": "https://jobaffinity.fr/apply/976itcfhzqxldumwbv"
    },
    "custom_fields": {
      "custom_filiere_metier": "Magasins",
      "custom_regions": "YVELINES SUD",
      "custom_temps_de_travail": "Temps plein"
    }
  }'
```

L'authentification recommandée est **Application Passwords** (*Utilisateurs > Profil > Mots de passe d'application*, natif WordPress 5.6+).

### Mise à jour ciblée d'un champ

```bash
curl -X POST https://votre-site.fr/wp-json/wp/v2/offer/1234 \
  -u "jobaffinity:app_password" \
  -H "Content-Type: application/json" \
  -d '{"meta": {"job_contract_type": "CDD"}}'
```

### Suppression d'un champ personnalisé

Envoyer `null` comme valeur, dans l'un ou l'autre canal :

```json
{ "meta": { "job_latitude": null } }
```

### Publier sur `/wp/v2/posts` plutôt que sur le CPT

Si l'option **Articles natifs** est cochée (par défaut), les mêmes clés sont également déclarées sur le post type `post` : `POST /wp-json/wp/v2/posts` avec un objet `meta` fonctionne à l'identique. L'objet `custom_fields`, lui, n'existe que sur le CPT.

L'option **Interception API REST** (désactivée par défaut) bascule automatiquement vers le CPT toute offre créée sur `/wp/v2/posts` contenant un `job_id` — le pendant REST de l'interception XML-RPC décrite au §4.

---

## 7. Afficher les offres sur le site public

### Cas A — Page d'archive automatique

Le CPT a `has_archive => true`, donc vos offres sont accessibles à l'URL :

```
https://votre-site.fr/offer/
```

WordPress cherche le template dans cet ordre :
1. `archive-offer.php` (si présent dans le thème)
2. `archive.php`
3. `index.php`

### Cas B — Page dédiée avec template personnalisé

Créez un fichier `archive-offer.php` (ou un template de page) dans votre thème :

```php
<?php
/* Template Name: Liste des offres */
get_header();

$query = new WP_Query( array(
    'post_type'      => 'offer',
    'posts_per_page' => 20,
    'orderby'        => 'date',
    'order'          => 'DESC',
) );

if ( $query->have_posts() ) : ?>
    <div class="offers-list">
    <?php while ( $query->have_posts() ) : $query->the_post();
        $contract = get_post_meta( get_the_ID(), 'job_contract_type', true );
        $location = get_post_meta( get_the_ID(), 'job_location', true );
        $link     = get_post_meta( get_the_ID(), 'job_link', true );
    ?>
        <article class="offer">
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p>
                <span class="contract"><?php echo esc_html( $contract ); ?></span>
                <span class="location"><?php echo esc_html( $location ); ?></span>
            </p>
            <?php the_excerpt(); ?>
            <?php if ( $link ) : ?>
                <a class="apply-btn" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">
                    Postuler
                </a>
            <?php endif; ?>
        </article>
    <?php endwhile;
    wp_reset_postdata(); ?>
    </div>
<?php else : ?>
    <p>Aucune offre disponible pour le moment.</p>
<?php endif;

get_footer();
```

### Cas C — Filtrer par champ personnalisé

Exemple pour n'afficher que les CDI en Île-de-France :

```php
$query = new WP_Query( array(
    'post_type'  => 'offer',
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key'   => 'job_contract_type',
            'value' => 'CDI',
        ),
        array(
            'key'     => 'custom_regions',
            'value'   => 'YVELINES',
            'compare' => 'LIKE',
        ),
    ),
) );
```

---

## 8. Tester l'intégration

1. Dans JobAffinity, allez dans **Admin > Publications**, ajoutez votre source WordPress avec le compte `jobaffinity`, son mot de passe d'application et la clé du CPT dans **Type de contenu**.
2. Publiez une offre test.
3. Dans WordPress, allez dans **Offres > Toutes les offres**. L'offre doit apparaître.
4. Ouvrez l'offre et scrollez jusqu'à la metabox **Champs personnalisés** : tous les `job_*` et `custom_*` doivent être présents.
5. Vérifiez via l'API REST :
   ```bash
   curl https://votre-site.fr/wp-json/wp/v2/offer?per_page=1
   ```
6. Vérifiez que les champs sont bien **déclarés** — c'est le contrôle qui détecte en une commande un champ qui serait perdu silencieusement :
   ```bash
   curl -X OPTIONS https://votre-site.fr/wp-json/wp/v2/offer \
     | python3 -c "import sys,json;print(sorted(json.load(sys.stdin)['schema']['properties']['meta']['properties']))"
   ```
   Toute clé absente de cette liste sera ignorée si vous l'envoyez dans `meta`.

---

## 9. Résolution des problèmes

### « Les offres apparaissent dans Articles et non dans Offres »

Le champ **Type de contenu** de la source JobAffinity est resté sur `post`. Corrigez-le en priorité (section 4) : c'est la cause la plus fréquente.

À défaut, activez l'interception correspondant à votre canal dans **Réglages > JobAffinity CPT Manager**. Si elle est déjà active et que le problème persiste :

- Vérifiez dans les offres déjà présentes dans *Articles* qu'elles contiennent bien la meta `job_id` (sans cette meta, le plugin ne les identifie pas comme JobAffinity).
- Les articles publiés **avant** l'activation de l'option restent dans *Articles*. Pour les migrer, utilisez WP-CLI :
  ```bash
  wp post list --meta_key=job_id --format=ids | xargs -n1 wp post update --post_type=offer
  ```

### « J'ai une erreur de publication sur mon site WordPress »

Consultez l'article [J'ai une erreur de publication sur mon site WordPress](https://help.jobaffinity.fr/fr/articles/) de JobAffinity. Les causes les plus fréquentes :

- Extension de sécurité (Wordfence, iThemes Security, Disable REST API) qui restreint `/wp-json/` : ajoutez une exception plutôt que d'ouvrir l'API entièrement.
- En-tête HTTP `Authorization` filtré par l'hébergeur : sans lui, le mot de passe d'application n'atteint jamais WordPress.
- Mot de passe incorrect : régénérez le mot de passe d'application côté WordPress et mettez à jour la source JobAffinity.
- Rôle insuffisant : le compte doit être au minimum Auteur.
- En XML-RPC : `xmlrpc.php` bloqué par l'extension de sécurité. Plutôt que d'y ajouter une exception, basculez la source sur l'API REST.

### « L'offre est créée (201) mais les champs envoyés dans `meta` sont absents »

C'est **le** piège de l'API REST : WordPress refuse d'écrire une clé `meta` qui n'a pas été déclarée côté serveur, et il la refuse **sans erreur**. On reçoit un `201 Created`, le post existe, et les champs ont disparu.

Le plugin déclare pour vous les 22 clés JobAffinity (§5). Si une clé manque — typiquement un champ `custom_*` propre à votre configuration :

1. listez ce que l'API expose réellement avec la commande `OPTIONS` du §8 ;
2. ajoutez la clé manquante dans *Réglages → JobAffinity CPT Manager*, champ **Champs supplémentaires** (une clé par ligne ; le socle des 22 clés JobAffinity reste déclaré quoi qu'il arrive) ;
3. ou envoyez-la via `custom_fields`, qui accepte n'importe quelle clé sans déclaration préalable.

### « Erreur `rest_invalid_type` sur `meta.job_salary_min` »

Toutes les clés sont déclarées `string`. Envoyer un nombre JSON (`28000` au lieu de `"28000"`) déclenche cette erreur.

Le plugin convertit automatiquement les nombres et booléens en chaînes avant validation, donc ce cas ne devrait plus se produire. En revanche, un **tableau** ou un **objet** reste rejeté volontairement : il s'agit d'une erreur côté client qu'il vaut mieux voir remonter que masquer.

À savoir : quand cette erreur survient, WordPress a **déjà inséré le post** avant de valider les meta. Une réponse 400 peut donc laisser derrière elle un article vide. Si un client réessaie en boucle, cherchez les doublons sans meta `job_id`.

### « Les champs personnalisés ne remontent pas dans `custom_fields` »

`custom_fields` retourne toutes les meta non protégées sans configuration préalable, mais il n'existe **que sur le CPT** : sur `/wp/v2/posts`, seul l'objet `meta` est disponible.

### « Les URLs dans `job_link` sont cassées (`&` transformés en `&amp;`) »

Ce cas est traité automatiquement par le plugin, sur les deux canaux : toute valeur commençant par `http://`, `https://` ou `//` est passée par `esc_url_raw`, qui préserve les caractères spéciaux des URLs. Les clés `job_link` et `apply_url` (et toute clé finissant par `_url` ou `_link`) sont en plus traitées comme des URLs quelle que soit leur valeur.

---

## 10. Désinstallation

Désactiver le plugin :

- ne supprime pas les offres existantes (elles restent en base mais ne sont plus visibles sans la ré-activation du plugin),
- ne supprime pas les champs personnalisés,
- ne supprime pas les réglages.

Pour une purge complète, exécutez dans l'admin WP-CLI :

```bash
wp post delete $(wp post list --post_type=offer --format=ids) --force
wp option delete ccptm_settings
```

---

## Annexe — Schéma d'intégration

```
┌─────────────────┐  POST /wp-json/wp/v2/offer   ┌──────────────────────┐
│                 │──────────────────────────────▶│                      │
│   JobAffinity   │   (mot de passe d'application)│  WordPress + plugin  │
│                 │◀──────────────────────────────│  JobAffinity CPT Mgr │
└─────────────────┘          201 Created          └──────────┬───────────┘
                                                             │
                                                             ▼
                                                   ┌──────────────────────┐
                                                   │  CPT "offer"         │
                                                   │  - title             │
                                                   │  - content           │
                                                   │  - custom_fields:    │
                                                   │    · job_id          │
                                                   │    · job_contract... │
                                                   │    · custom_regions  │
                                                   └──────────┬───────────┘
                                                             │
                                         ┌───────────────────┼───────────────────┐
                                         ▼                   ▼                   ▼
                                ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
                                │ Page publique│    │  API REST   │    │  Admin WP   │
                                │ archive-offer│    │ /wp/v2/offer│    │   (menu)    │
                                └─────────────┘    └─────────────┘    └─────────────┘
```
