# Custom CPT Manager

Plugin WordPress qui crée un Custom Post Type (CPT) configurable, identique aux posts natifs, pleinement exposé dans l'API REST avec support des champs personnalisés arbitraires.

## Fonctionnalités

- **Clé configurable à l'installation** : après activation, un écran dans *Réglages → Custom CPT Manager* permet de choisir la clé (ex : `offer`), les libellés et l'icône.
- **Identique aux posts natifs** : mêmes supports (title, editor, author, thumbnail, excerpt, custom-fields, comments, revisions, post-formats), mêmes taxonomies (catégories, étiquettes), même logique de capabilities.
- **Rôles autorisés** : administrateurs, éditeurs et auteurs (héritage via `capability_type => 'post'`).
- **API REST complète** : endpoint `/wp-json/wp/v2/<base>` avec support `title`, `content`, `excerpt`, `status`, etc.
- **Base de route REST découplée** : la route peut différer de la clé du CPT (ex. CPT `offer-intern` exposé sur `/wp/v2/offer`), avec détection des collisions.
- **Champs déclarés dans `meta`** : un socle obligatoire de 22 clés JobAffinity est déclaré via `register_post_meta()` et devient utilisable dans l'objet `meta` standard de l'API REST — y compris sur les articles natifs. Ce socle ne peut pas être retiré ; les réglages servent à **ajouter** des champs par-dessus (*Champs supplémentaires*).
- **Champs personnalisés arbitraires** : un champ `custom_fields` (ou `meta_input` en alias) permet d'envoyer un objet clé/valeur sans pré-déclaration, pour les clés libres et les valeurs multiples.

## Installation

1. Copier le dossier `custom-cpt-manager/` dans `wp-content/plugins/`.
2. Activer le plugin dans l'admin WordPress.
3. Aller dans **Réglages → Custom CPT Manager**.
4. Renseigner la clé (ex : `offer`) et enregistrer.
5. Un nouveau menu (ex : *Offres*) apparaît dans l'admin.

## Utilisation API REST

### Authentification

Tout appel en écriture nécessite une authentification. Recommandé : *Application Passwords* (WordPress 5.6+).

### `meta` ou `custom_fields` ?

| | `meta` | `custom_fields` |
|---|---|---|
| Standard WordPress | oui | non (spécifique au plugin) |
| Clés acceptées | uniquement les clés déclarées | n'importe quelle clé |
| Valeurs multiples | non | oui (tableau indexé) |
| Disponible sur `/wp/v2/posts` | oui (si l'option est cochée) | non |

WordPress **ignore silencieusement** toute clé non déclarée envoyée dans `meta` : la requête répond `201` et le champ est perdu. C'est pourquoi le plugin déclare la liste configurée dans les réglages. Pour vérifier ce qui est réellement exposé :

```bash
curl -X OPTIONS https://example.com/wp-json/wp/v2/offer \
  | python3 -c "import sys,json;print(sorted(json.load(sys.stdin)['schema']['properties']['meta']['properties']))"
```

Si la même clé arrive par les deux canaux dans une requête, c'est la valeur de `custom_fields` qui est conservée.

### Créer un élément avec des champs déclarés (`meta`)

```bash
curl -X POST https://example.com/wp-json/wp/v2/offer \
  -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Vendeur H/F - Versailles",
    "status": "publish",
    "meta": {
      "job_id": "1023736",
      "job_contract_type": "CDI",
      "job_salary_min": "28000"
    }
  }'
```

Les clés sont toutes typées `string`. Un nombre ou un booléen JSON est converti automatiquement en chaîne avant validation ; un tableau ou un objet reste rejeté (`rest_invalid_type`).

### Créer un élément avec des champs arbitraires (`custom_fields`)

```bash
curl -X POST https://example.com/wp-json/wp/v2/offer \
  -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Offre de printemps",
    "content": "<p>Description de l offre...</p>",
    "status": "publish",
    "custom_fields": {
      "prix": "99.90",
      "devise": "EUR",
      "stock": 42,
      "tags_internes": ["promo", "nouveau"]
    }
  }'
```

### Lire un élément

```bash
curl https://example.com/wp-json/wp/v2/offer/123
```

La réponse inclut un objet `custom_fields` avec toutes les meta non protégées.

### Mettre à jour un champ personnalisé

```bash
curl -X POST https://example.com/wp-json/wp/v2/offer/123 \
  -u "user:app_password" \
  -H "Content-Type: application/json" \
  -d '{"custom_fields": {"stock": 10}}'
```

### Supprimer une meta

Envoyer `null` comme valeur :

```json
{ "custom_fields": { "prix": null } }
```

## Notes de sécurité

- Les meta protégées (préfixées par `_`, ex : `_wp_page_template`) sont **filtrées** en lecture et **refusées** en écriture sauf si l'utilisateur dispose explicitement de la capacité `edit_post_meta` correspondante.
- Les valeurs de type chaîne passent par `wp_kses_post` (même règle que le contenu d'un post). Les clés `job_link`, `apply_url` et toute clé finissant par `_url` ou `_link` passent par `esc_url_raw`, qui préserve les `&` des URLs.
- La sanitisation est branchée via `register_post_meta()` : elle s'applique donc à **tous** les chemins d'écriture d'un coup — objet `meta`, `custom_fields`, XML-RPC et metabox « Champs personnalisés ».
- L'écriture d'une meta déclarée exige la capacité `edit_post` sur le post visé, pas seulement `edit_posts`.
- Les tableaux indexés sont stockés comme meta multi-valeurs (`add_post_meta` en boucle après un `delete_post_meta` initial) pour rester compatible avec `get_post_meta($id, $key, false)`.

## Changer la clé après coup

La clé peut être modifiée, mais cela :

- change les URLs publiques du CPT,
- change la base de route REST **si celle-ci est laissée vide** (sinon la route configurée est conservée),
- ne migre pas automatiquement les posts existants (ils resteront associés à l'ancien `post_type` tant qu'aucune migration manuelle n'est effectuée).

Il est fortement recommandé de définir la clé définitive avant de créer du contenu.

## Désinstallation

Désactiver le plugin ne supprime ni les réglages ni les contenus. Pour une purge complète, supprimer l'option `ccptm_settings` et les posts du CPT via un script dédié.

## Structure

```
custom-cpt-manager/
├── custom-cpt-manager.php          # Point d'entrée
├── includes/
│   ├── class-ccptm-settings.php    # Gestion des options
│   ├── class-ccptm-cpt.php         # Enregistrement du CPT
│   ├── class-ccptm-meta.php        # Déclaration des meta pour l'API REST
│   ├── class-ccptm-rest.php        # Champs REST (custom_fields) + interception
│   ├── class-ccptm-xmlrpc.php      # Interception XML-RPC
│   └── class-ccptm-admin.php       # Page de configuration
├── INTEGRATION-JOBAFFINITY.md
└── README.md
```

## Multisite

Les réglages sont stockés dans l'option **par site** `ccptm_settings`. Sur un réseau, chaque site a donc sa propre clé de CPT, sa propre base de route et sa propre liste de champs déclarés — ce qui est généralement souhaitable, les champs `custom_*` variant d'un site à l'autre. Un site sans réglage n'enregistre ni CPT ni meta.

## Filtres pour les développeurs

| Filtre | Rôle |
|---|---|
| `ccptm_meta_keys` | Ajouter des clés à la liste déclarée (le socle JobAffinity est réinjecté après le filtre et ne peut pas en être retiré) |
| `ccptm_meta_post_types` | Modifier les post types recevant les déclarations |
| `ccptm_sanitize_meta_value` | Personnaliser la sanitisation d'une meta déclarée |

## Compatibilité

Le plugin `offer-xmlrpc`, s'il est activé en même temps avec la clé `offer`, enregistre le même post type à la priorité `init` 10 **sans** `show_in_rest` et écrase donc l'enregistrement de ce plugin (priorité 5), ce qui supprime la route REST. N'activez pas les deux simultanément.
