# Starter Live

**English** · [Italiano](#italiano)

Edit texts, images, videos and links straight from the frontend. It works with Gutenberg blocks and with the ACF blocks of Starter Theme, **without changing the theme**.

## Usage
1. When logged in (with permission to edit the page), **✎ Modifica contenuti** appears at the bottom center (and in the admin bar).
2. Hover an element: it is highlighted with its label. Click it and change the content → **Applica** (instant preview).
3. **Salva e chiudi** writes all the changes together. **Annulla** discards them.

## What can be edited
| Source | Content |
|---|---|
| ACF | text, textarea, wysiwyg, link, image, file/video, gallery (also inside repeaters/groups and nested blocks) |
| Gutenberg | paragraph, heading, list item, button, image, cover (background), video, media & text |

Video: you change the file and, in the same popup, also the **cover** (poster) shown while it loads. Galleries are edited as a single element (add, remove, reorder; the ACF field's min/max is respected).

Not editable from the frontend (use the backend): select/true-false/colors, menu/header/footer (Customizer / ACF Options settings).

## Text formatting
All the module texts (eyebrows, headings, texts, items, contacts, lists…) have the **B / I / U** bar (rich texts also have the link) and keyboard shortcuts. Only these stay plain text: the title and name of pages and categories, the eyebrow saved as a field, the map address and the button texts. In the theme, block texts are printed with `starter_inline()` (inline formatting only). In Gutenberg blocks underline is saved as `<span style="text-decoration: underline">` and bold/italic as `<strong>`/`<em>`.

## Cache
For anyone who can edit the page it is never cached (DONOTCACHEPAGE and the `X-LiteSpeed-Cache-Control: no-cache` header). With LiteSpeed Cache, if the button does not show up, exclude `starter-live` from Minify / Combine / Delay JS.

## What else can be edited besides blocks
- **Title, eyebrow and intro text** of Blog, Shop, categories and tags (even when the text does not exist yet: an optional space visible only in edit mode).
- Gutenberg **lists** as a single block (add / remove / reorder items).
- **Icons** (theme library, logos, uploaded SVGs) in ACF "Icon" fields.
- **WooCommerce products** (product page): short description, long description (if it is classic text; if it is built with blocks, you edit the blocks) and images (the first is the main one, the others are the gallery).
- **Contacts** of the Contacts block (text and icon of each row).
- **Title** and **featured image** of pages and posts (if the page prints the title and image with the theme).
- **Alt text** of block images: when you change the image, the new image's alt from the Media Library is suggested (editable). For ACF fields the alt is always the Library's.
- **Map address** (also updates the "Directions" link).
- **Shortcodes** (e.g. the Contact Form 7 form of the Contacts block and Gutenberg "Shortcode" blocks): you pick the form from a list or write the shortcode, with a preview before saving. In the theme, the element that contains an ACF field's shortcode must be marked with `data-live-shortcode="field_name"`.

## ACF-field records (not blocks)

A content type built with ACF fields instead of blocks (e.g. a property record) can be made editable from the frontend:

1. the theme declares the type with the `starter_live_acf_post_types` filter (e.g. `immobile`);
2. it passes the record's HTML through the `starter_live_post_html` filter (`apply_filters( 'starter_live_post_html', $html, $post_id )`): the plugin hooks the ACF fields' texts, images and galleries by itself, as in blocks;
3. for **numbers** (price, surface…) the theme marks the element with `starter_live_attrs( array( 'src' => 'acfnum', 'key' => 'field_name' ) )`; in the popup you type the number and only the figure changes on the page; the same marker works for **choices from a list** (single or multiple dropdown: in the popup a list or checkboxes, saved only if they are among the field's choices) and for short texts; if several elements show the same field, they all change together; for **lists written in a text field, one item per line** (e.g. a service's checkmark list) the theme marks the `<ul>` with `starter_live_attrs( array( 'src' => 'acflines', 'key' => 'field_name' ) )` and in the popup you edit the whole list (Enter = new item, Backspace on an empty item removes it), saved one item per line;
4. with `starter_live_acf_post_skip` you exclude the fields that the theme does not show in the record.

Cases in use: **properties** ("immobili") and **Portfolio projects** (`portfolio`): from the header you edit title, summary (`post_excerpt`), text under the title (`acfnum` marker), agency names and links, project link; the `progetto-*` blocks (credits included, with the collaborators' links) like all ACF blocks.

Security: same sanitizing as block fields; only real ACF fields (with a `_name` reference in the database) of declared content types are saved, and only by whoever can edit that content (`edit_post`).

## Phone
On screens up to 782 px (phone) frontend editing is disabled: no floating button, no entry in the WordPress bar and no edit mode (too awkward). It is used from tablet up.

## Security
- **Who can do what:** no new permissions. Every change is checked on the single page, category or product (`edit_post` / `edit_term`), as in the backend; without permission the request is refused and reveals nothing. Whoever cannot edit does not even receive the script or the button.
- **Forged requests from other sites (CSRF):** every save requires the WordPress nonce, otherwise it is refused.
- **What can be written:** only existing fields of blocks already present (names, paths and types are verified); no creation of new fields, no change of status/author/slug. Texts are cleaned on the server (`wp_kses`: no scripts, `onclick`/`onerror` events, `javascript:`); images only if they are real attachments; icons only if they exist; shortcodes only if registered and only one at a time.
- **Text already saved in the database:** before entering the editor it passes through two filters (server and browser), so code hidden in a field cannot run in the editor's browser.
- **Contact with the outside:** the plugin uses no services or CDNs; everything is local to the site. The only exception is the update check on GitHub (see *Updates*), which can be turned off. SVG upload stays reserved to administrators and SVGs are sanitized.
- **Revisions:** block saves create WordPress revisions. Titles, eyebrows and descriptions of categories do not (as in the backend).

## How it works
- `Starter_Live_Frontend::tag_blocks()` (`the_content`, priority 8) gives each block its path in `post_content` (e.g. `2.1.0`).
- `Starter_Live_Annotator` (`render_block`, priority 20) matches the blocks' values with the rendered HTML (identical text, link with the same href, image with the same file) and adds `data-live="pN"`. For ACF blocks it reads `attrs.data`.
- The field registry is passed to the JS in the footer (`window.StarterLive`).
- `POST /wp-json/starter-live/v1/save` rewrites the right block and saves with `wp_update_post`: **WordPress creates a revision on every save**, so you can go back from the backend.

## Security (summary)
- Only users with `edit_post` on the page (filter `starter_live_can_edit` to restrict, e.g. to a "client" role).
- REST nonce, check that the block at the given path is the expected one, sanitizing by field type (HTML reduced to b/i/u/a/br/sub/sup).
- Users who are not logged in receive no extra markup.

## Known limits (v0.1)
- It works on single pages/posts (`is_singular`), not on archives or the "Blog" page.
- A text that cannot be matched to the HTML (transformed by the theme) stays non-editable from the frontend.
- Reusable blocks / synced patterns cannot be edited from the frontend.

## Updates

The plugin checks the latest release of [StarterLiveWP](https://github.com/BurOrub/StarterLiveWP) on GitHub (every 6 hours) and WordPress shows the usual update notice. To turn it off: `define( 'STARTER_LIVE_UPDATE_REPO', '' );` in `wp-config.php`. On a development copy (folder with `.git`) no notice is shown. For a private repository, define `STARTER_GITHUB_TOKEN` (read-only token).

Publishing a version: raise `Version:` in `starter-live.php` (and `STARTER_LIVE_VERSION`), commit, `git tag vX.Y.Z`, `git push origin main vX.Y.Z`. The workflow in `.github/workflows/release.yml` builds `starter-live.zip` and attaches it to the release.

---

## Italiano

Modifica testi, immagini, video e link direttamente dal frontend. Funziona con i blocchi Gutenberg e con i blocchi ACF del Tema Starter, **senza modificare il tema**.

### Uso
1. Da loggato (con permesso di modifica sulla pagina) compare in basso al centro **✎ Modifica contenuti** (anche nella barra admin).
2. Passa sopra un elemento: si evidenzia con la sua etichetta. Cliccalo e cambia il contenuto → **Applica** (anteprima immediata).
3. **Salva e chiudi** scrive tutte le modifiche insieme. **Annulla** le scarta.

### Cosa si può modificare
| Origine | Contenuti |
|---|---|
| ACF | text, textarea, wysiwyg, link, image, file/video, gallery (anche dentro repeater/group e blocchi annidati) |
| Gutenberg | paragrafo, titolo, voce elenco, pulsante, immagine, cover (sfondo), video, media-testo |

Video: si cambia il file e, nello stesso popup, anche la **copertina** (poster) mostrata mentre carica. Le gallery si modificano come un unico elemento (aggiungi, rimuovi, riordina; rispetta min/max del campo ACF).

Non modificabili da frontend (si usa il backend): select/true-false/colori, menu/header/footer (opzioni del Customizer/ACF Options).

### Formattazione dei testi
Tutti i testi dei moduli (occhielli, titoli, testi, voci, recapiti, elenchi…) hanno la barra **B / I / U** (i testi ricchi anche il link) e le scorciatoie da tastiera. Restano testo semplice solo titolo e nome di pagine e categorie, l'occhiello salvato come campo, l'indirizzo della mappa e i testi dei pulsanti. Nel tema i testi dei blocchi si stampano con `starter_inline()` (solo formattazione in linea). Nei blocchi Gutenberg il sottolineato si salva come `<span style="text-decoration: underline">` e grassetto/corsivo come `<strong>`/`<em>`.

### Cache
Per chi può modificare la pagina non viene mai messa in cache (DONOTCACHEPAGE e intestazione `X-LiteSpeed-Cache-Control: no-cache`). Con LiteSpeed Cache, se il pulsante non compare, escludere `starter-live` da Minimizza / Combina / Ritarda JS.

### Cosa si modifica oltre ai blocchi
- **Titolo, occhiello e testo introduttivo** di Blog, Shop, categorie e tag (anche se il testo non esiste ancora: spazio facoltativo visibile solo in modalità modifica).
- **Elenchi** di Gutenberg come blocco unico (aggiungi / togli / riordina le voci).
- **Icone** (libreria del tema, loghi, SVG caricati) nei campi ACF "Icona".
- **Prodotti WooCommerce** (scheda prodotto): descrizione breve, descrizione lunga (se è testo classico; se è fatta con blocchi, si modificano i blocchi) e immagini (la prima è la principale, le altre la galleria).
- **Recapiti** del blocco Contatti (testo e icona di ogni riga).
- **Titolo** e **immagine in evidenza** di pagine e articoli (se la pagina stampa il titolo e l'immagine con il tema).
- **Testo alternativo (alt)** delle immagini dei blocchi: cambiando immagine si propone quello della nuova immagine nella Libreria media (modificabile). Per i campi ACF l'alt è sempre quello della Libreria.
- **Indirizzo della mappa** (aggiorna anche il link "Indicazioni stradali").
- **Shortcode** (es. il modulo Contact Form 7 del blocco Contatti e i blocchi "Shortcode" di Gutenberg): si sceglie il modulo da un elenco o si scrive lo shortcode, con anteprima prima di salvare. Nel tema, l'elemento che contiene lo shortcode di un campo ACF va marcato con `data-live-shortcode="nome_campo"`.

### Schede con campi ACF (non blocchi)

Un tipo di contenuto fatto con campi ACF invece che con blocchi (es. la scheda di un immobile) si può rendere modificabile dal frontend:

1. il tema dichiara il tipo con il filtro `starter_live_acf_post_types` (es. `immobile`);
2. passa l'HTML della scheda dal filtro `starter_live_post_html` (`apply_filters( 'starter_live_post_html', $html, $post_id )`): il plugin aggancia da solo testi, immagini e gallerie dei campi ACF, come nei blocchi;
3. per i **numeri** (prezzo, superficie…) il tema indica l'elemento con `starter_live_attrs( array( 'src' => 'acfnum', 'key' => 'nome_campo' ) )`; nel popup si scrive il numero e nella pagina cambia solo la cifra; lo stesso marcatore vale per le **scelte da elenco** (menu a tendina singolo o multiplo: nel popup un elenco o delle caselle, salvate solo se tra le scelte del campo) e per i testi brevi; se più elementi mostrano lo stesso campo, cambiano tutti insieme; per gli **elenchi scritti in un campo di testo, una voce per riga** (es. l'elenco con spunte di un servizio) il tema marca il `<ul>` con `starter_live_attrs( array( 'src' => 'acflines', 'key' => 'nome_campo' ) )` e nel popup si modifica l'intera lista (Invio = nuova voce, Backspace su una voce vuota la toglie), salvata una voce per riga;
4. con `starter_live_acf_post_skip` si escludono i campi che il tema non mostra nella scheda.

Casi in uso: gli **immobili** e i **progetti del Portfolio** (`portfolio`): dalla testata si modificano titolo, riassunto (`post_excerpt`), testo sotto il titolo (marcatore `acfnum`), nomi e link delle agenzie, link al progetto; i blocchi `progetto-*` (crediti compresi, con i link ai collaboratori) come tutti i blocchi ACF.

Sicurezza: stesse pulizie dei campi dei blocchi; si salvano solo campi ACF veri (con riferimento `_nome` nel database) di tipi di contenuto dichiarati, e solo da chi può modificare quel contenuto (`edit_post`).

### Telefono
Su schermi fino a 782 px (telefono) la modifica dal frontend è disattivata: nessun pulsante flottante, nessuna voce nella barra di WordPress e nessuna modalità modifica (troppo scomoda). Si usa da tablet in su.

### Sicurezza
- **Chi può cosa:** nessun permesso nuovo. Ogni modifica è controllata sulla singola pagina, categoria o prodotto (`edit_post` / `edit_term`), come nel backend; senza permesso la richiesta è rifiutata e non rivela niente. Chi non può modificare non riceve nemmeno lo script né il pulsante.
- **Richieste false da altri siti (CSRF):** ogni salvataggio richiede il nonce di WordPress, altrimenti è rifiutato.
- **Cosa si può scrivere:** solo campi già esistenti di blocchi già presenti (nomi, percorsi e tipi sono verificati); niente creazione di nuovi campi, niente cambio di stato/autore/slug. Testi ripuliti sul server (`wp_kses`: via script, eventi `onclick`/`onerror`, `javascript:`); immagini solo se sono allegati reali; icone solo se esistono; shortcode solo se registrato e uno solo per volta.
- **Testo già salvato nel database:** prima di entrare nell'editor passa da due filtri (server e browser), così codice nascosto in un campo non si può eseguire nel browser di chi modifica.
- **Contatti con l'esterno:** il plugin non usa servizi né CDN; tutto è locale al sito. L'unica eccezione è il controllo degli aggiornamenti su GitHub (vedi *Aggiornamenti*), che si può spegnere. Il caricamento di SVG resta riservato agli amministratori e gli SVG vengono ripuliti.
- **Revisioni:** i salvataggi dei blocchi creano revisioni di WordPress. Titoli, occhielli e descrizioni di categorie no (come nel backend).

### Come funziona
- `Starter_Live_Frontend::tag_blocks()` (`the_content`, prio 8) assegna a ogni blocco il suo percorso nel `post_content` (es. `2.1.0`).
- `Starter_Live_Annotator` (`render_block`, prio 20) abbina i valori dei blocchi all'HTML renderizzato (testo identico, link con lo stesso href, immagine con lo stesso file) e aggiunge `data-live="pN"`. Per i blocchi ACF legge `attrs.data`.
- Il registro dei campi viene passato al JS nel footer (`window.StarterLive`).
- `POST /wp-json/starter-live/v1/save` riscrive il blocco giusto e salva con `wp_update_post`: **WordPress crea una revisione a ogni salvataggio**, quindi si può tornare indietro dal backend.

### Sicurezza (in breve)
- Solo utenti con `edit_post` sulla pagina (filtro `starter_live_can_edit` per restringere, es. a un ruolo "cliente").
- Nonce REST, controllo che il blocco al percorso indicato sia quello atteso, sanitizzazione per tipo di campo (HTML ridotto a b/i/u/a/br/sub/sup).
- Chi non è loggato non riceve nessun markup aggiuntivo.

### Limiti noti (v0.1)
- Funziona sulle pagine/articoli singoli (`is_singular`), non su archivi o pagina "Blog".
- Un testo non abbinabile all'HTML (trasformato dal tema) resta non modificabile da frontend.
- Blocchi riutilizzabili/pattern sincronizzati non sono modificabili da frontend.

### Aggiornamenti

Il plugin controlla l'ultima release di [StarterLiveWP](https://github.com/BurOrub/StarterLiveWP) su GitHub (ogni 6 ore) e WordPress mostra la notifica normale di aggiornamento. Per spegnerlo: `define( 'STARTER_LIVE_UPDATE_REPO', '' );` in `wp-config.php`. Sulle copie di sviluppo (cartella con `.git`) la notifica non compare. Per un repository privato definisci `STARTER_GITHUB_TOKEN` (token di sola lettura).

Pubblicare una versione: alza `Version:` in `starter-live.php` (e `STARTER_LIVE_VERSION`), commit, `git tag vX.Y.Z`, `git push origin main vX.Y.Z`. L'automazione in `.github/workflows/release.yml` crea `starter-live.zip` e lo allega alla release.
