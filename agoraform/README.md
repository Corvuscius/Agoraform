# Agoraform

Mini-outil de formulaires, façon Framaforms, pour un hébergement mutualisé classique (OVH…).
PHP 7.4+ sans dépendance, pas de base de données : un **descripteur CSV** décrit le formulaire,
les réponses sont ajoutées à un **fichier CSV**. Interface FR / EN.

## Organisation et installation
```
public/                  → seul dossier public : à envoyer dans www/ (ex. www/agoraform/)
agoraform-private/       → PRIVÉ : à envoyer à côté de www/ (ex. /home/compte/agoraform-private)
    config.php  src/  lang/
    forms/<id>.csv       descripteurs de formulaires
    data/<id>.csv        saisies
```
1. Envoyer le contenu de `public/` dans `www/agoraform/` et le dossier `agoraform-private/` au même niveau que `www/`.
2. Ouvrir `https://votre-site/agoraform/` : la liste des formulaires de `forms/` s'affiche.

**Les CSV ne sont jamais publics.** Le dossier privé est cherché à côté de la racine web
(ligne `define('AF_PRIVATE', …)` de `public/index.php`, modifiable). Agoraform refuse de démarrer
si `forms_dir` ou `data_dir` (`config.php`) se trouvent dans la racine web ; un `.htaccess` de
sécurité est aussi fourni dans le dossier privé.

## Descripteur de formulaire
Colonnes : `name;type;required;visible_if;label_fr;label_en;options_fr;options_en`

| type       | rôle |
|------------|------|
| `title`    | titre du formulaire (labels uniquement) |
| `intro`    | texte d'introduction (labels uniquement) |
| `text`     | champ texte (une ligne) |
| `textarea` | texte sur plusieurs lignes |
| `radio`    | choix unique ; `options_xx` obligatoire (≥ 2 choix séparés par `\|`) |
| `checkbox` | `options_xx` vide → case unique (stockée `1`/`0`) ; sinon cases multiples (stockées `A \| B`) |

- `required` : `1` ou `oui`/`yes` = obligatoire, vide ou `0` = facultatif.
- `name` : identifiant du champ (lettres, chiffres, `_`) = nom de colonne dans le fichier de saisie
  (`id`, `date` et `lang` sont réservés).
- Les options sont enregistrées dans la **langue par défaut** : données homogènes quelle que soit la langue du répondant.
- Ajouter une langue : créer `lang/xx.php`, l'ajouter à `langs` dans `config.php`, ajouter les colonnes `label_xx` / `options_xx`.

### Champs conditionnels (`visible_if`)
Un champ peut n'apparaître que selon la saisie de champs **radio/checkbox placés avant lui** :

| écriture | sens |
|----------|------|
| `profil=Média\|Bénévole` | l'un de ces choix est sélectionné |
| `profil!=Bénévole` | aucun de ces choix n'est sélectionné |
| `arrivee=Vendredi soir & rgpd=1` | `&` = ET (case unique : `1` cochée, `0` non cochée) |

Les valeurs sont les options dans la langue par défaut (casse ignorée) ; une faute de frappe est signalée au chargement.
Un champ masqué n'est **jamais obligatoire ni enregistré** (colonne vide), même si une valeur est forgée dans la requête ;
si un champ dont on dépend est lui-même masqué, le champ suivant l'est aussi.
L'affichage dynamique utilise un peu de JavaScript ; sans lui, tous les champs sont visibles mais le serveur applique les mêmes règles.

## Identifiant de saisie
Aucune authentification : chaque réponse enregistrée reçoit un **identifiant unique et aléatoire**
(`XXXX-XXXX-XXXX`, 60 bits, alphabet sans caractères ambigus), écrit dans la colonne `id` du CSV
et affiché au répondant sur la page de confirmation.

Colonnes du fichier de saisie : `id;date;lang;<champs…>` (UTF-8 avec BOM, séparateur `;` : s'ouvre directement dans Excel).
Si vous modifiez les champs d'un formulaire déjà utilisé, l'ancien fichier est archivé (`<id>.AAAAMMJJHHMMSS.csv`)
et un nouveau démarre avec les nouvelles colonnes.

## Exemple fourni
`forms/inscription.csv` reprend la structure du tableau « Inscription générique » (profil, nom, contact,
arrivée, repas, stand, RGPD, commentaires) avec ses champs conditionnels ; `data/inscription.csv` contient une ligne de saisie fictive.
Supprimez-la avant la mise en production.

## Sécurité
- Dossier privé hors racine web, vérifié au démarrage.
- Jeton CSRF, pot de miel anti-robots, échappement HTML, écriture protégée par verrou (`flock`).
- Les valeurs commençant par `= + - @` sont préfixées d'une apostrophe (injection de formules Excel).
- Pas de page d'administration : récupérez les réponses par FTP.
