# Starter Live

Modifica testi, immagini, video e link direttamente dal frontend. Funziona con i blocchi Gutenberg e con i blocchi ACF del Tema Starter, **senza modificare il tema**.

## Uso
1. Da loggato (con permesso di modifica sulla pagina) compare in basso al centro **✎ Modifica contenuti** (anche nella barra admin).
2. Passa sopra un elemento: si evidenzia con la sua etichetta. Cliccalo e cambia il contenuto → **Applica** (anteprima immediata).
3. **Salva e chiudi** scrive tutte le modifiche insieme. **Annulla** le scarta.

## Cosa si può modificare
| Origine | Contenuti |
|---|---|
| ACF | text, textarea, wysiwyg, link, image, file/video, gallery (anche dentro repeater/group e blocchi annidati) |
| Gutenberg | paragrafo, titolo, voce elenco, pulsante, immagine, cover (sfondo), video, media-testo |

Video: si cambia il file e, nello stesso popup, anche la **copertina** (poster) mostrata mentre carica. Le gallery si modificano come un unico elemento (aggiungi, rimuovi, riordina; rispetta min/max del campo ACF).

Non modificabili da frontend (si usa il backend): select/true-false/colori, shortcode, indirizzo mappa, menu/header/footer (opzioni del Customizer/ACF Options).

## Formattazione dei testi
Tutti i testi dei moduli (occhielli, titoli, testi, voci, recapiti, elenchi…) hanno la barra **B / I / U** (i testi ricchi anche il link) e le scorciatoie da tastiera. Restano testo semplice solo titolo e nome di pagine e categorie, l'occhiello salvato come campo, l'indirizzo della mappa e i testi dei pulsanti. Nel tema i testi dei blocchi si stampano con `starter_inline()` (solo formattazione in linea). Nei blocchi Gutenberg il sottolineato si salva come `<span style="text-decoration: underline">` e grassetto/corsivo come `<strong>`/`<em>`.

## Cache
Per chi può modificare la pagina non viene mai messa in cache (DONOTCACHEPAGE e intestazione `X-LiteSpeed-Cache-Control: no-cache`). Con LiteSpeed Cache, se il pulsante non compare, escludere `starter-live` da Minimizza / Combina / Ritarda JS.

## Cosa si modifica oltre ai blocchi
- **Titolo, occhiello e testo introduttivo** di Blog, Shop, categorie e tag (anche se il testo non esiste ancora: spazio facoltativo visibile solo in modalità modifica).
- **Elenchi** di Gutenberg come blocco unico (aggiungi / togli / riordina le voci).
- **Icone** (libreria del tema, loghi, SVG caricati) nei campi ACF "Icona".
- **Prodotti WooCommerce** (scheda prodotto): descrizione breve, descrizione lunga (se è testo classico; se è fatta con blocchi, si modificano i blocchi) e immagini (la prima è la principale, le altre la galleria).
- **Recapiti** del blocco Contatti (testo e icona di ogni riga).
- **Titolo** e **immagine in evidenza** di pagine e articoli (se la pagina stampa il titolo e l'immagine con il tema).
- **Testo alternativo (alt)** delle immagini dei blocchi: cambiando immagine si propone quello della nuova immagine nella Libreria media (modificabile). Per i campi ACF l'alt è sempre quello della Libreria.
- **Indirizzo della mappa** (aggiorna anche il link "Indicazioni stradali").
- **Shortcode** (es. il modulo Contact Form 7 del blocco Contatti e i blocchi "Shortcode" di Gutenberg): si sceglie il modulo da un elenco o si scrive lo shortcode, con anteprima prima di salvare. Nel tema, l'elemento che contiene lo shortcode di un campo ACF va marcato con `data-live-shortcode="nome_campo"`.

## Schede con campi ACF (non blocchi)

Un tipo di contenuto fatto con campi ACF invece che con blocchi (es. la scheda di un immobile) si può rendere modificabile dal frontend:

1. il tema dichiara il tipo con il filtro `starter_live_acf_post_types` (es. `immobile`);
2. passa l'HTML della scheda dal filtro `starter_live_post_html` (`apply_filters( 'starter_live_post_html', $html, $post_id )`): il plugin aggancia da solo testi, immagini e gallerie dei campi ACF, come nei blocchi;
3. per i **numeri** (prezzo, superficie…) il tema indica l'elemento con `starter_live_attrs( array( 'src' => 'acfnum', 'key' => 'nome_campo' ) )`; nel popup si scrive il numero e nella pagina cambia solo la cifra; lo stesso marcatore vale per le **scelte da elenco** (menu a tendina singolo o multiplo: nel popup un elenco o delle caselle, salvate solo se tra le scelte del campo) e per i testi brevi; se più elementi mostrano lo stesso campo, cambiano tutti insieme; per gli **elenchi scritti in un campo di testo, una voce per riga** (es. l'elenco con spunte di un servizio) il tema marca il `<ul>` con `starter_live_attrs( array( 'src' => 'acflines', 'key' => 'nome_campo' ) )` e nel popup si modifica l'intera lista (Invio = nuova voce, Backspace su una voce vuota la toglie), salvata una voce per riga;
4. con `starter_live_acf_post_skip` si escludono i campi che il tema non mostra nella scheda.

Casi in uso: gli **immobili** e i **progetti del Portfolio** (`portfolio`): dalla testata si modificano titolo, riassunto (`post_excerpt`), testo sotto il titolo (marcatore `acfnum`), nomi e link delle agenzie, link al progetto; i blocchi `progetto-*` (crediti compresi, con i link ai collaboratori) come tutti i blocchi ACF.

Sicurezza: stesse pulizie dei campi dei blocchi; si salvano solo campi ACF veri (con riferimento `_nome` nel database) di tipi di contenuto dichiarati, e solo da chi può modificare quel contenuto (`edit_post`).

## Telefono
Su schermi fino a 782 px (telefono) la modifica dal frontend è disattivata: nessun pulsante flottante, nessuna voce nella barra di WordPress e nessuna modalità modifica (troppo scomoda). Si usa da tablet in su.

## Sicurezza
- **Chi può cosa:** nessun permesso nuovo. Ogni modifica è controllata sulla singola pagina, categoria o prodotto (`edit_post` / `edit_term`), come nel backend; senza permesso la richiesta è rifiutata e non rivela niente. Chi non può modificare non riceve nemmeno lo script né il pulsante.
- **Richieste false da altri siti (CSRF):** ogni salvataggio richiede il nonce di WordPress, altrimenti è rifiutato.
- **Cosa si può scrivere:** solo campi già esistenti di blocchi già presenti (nomi, percorsi e tipi sono verificati); niente creazione di nuovi campi, niente cambio di stato/autore/slug. Testi ripuliti sul server (`wp_kses`: via script, eventi `onclick`/`onerror`, `javascript:`); immagini solo se sono allegati reali; icone solo se esistono; shortcode solo se registrato e uno solo per volta.
- **Testo già salvato nel database:** prima di entrare nell'editor passa da due filtri (server e browser), così codice nascosto in un campo non si può eseguire nel browser di chi modifica.
- **Nessun contatto con l'esterno:** niente servizi, CDN o aggiornamenti remoti; tutto è locale al sito. Il caricamento di SVG resta riservato agli amministratori e gli SVG vengono ripuliti.
- **Revisioni:** i salvataggi dei blocchi creano revisioni di WordPress. Titoli, occhielli e descrizioni di categorie no (come nel backend).

## Come funziona
- `Starter_Live_Frontend::tag_blocks()` (`the_content`, prio 8) assegna a ogni blocco il suo percorso nel `post_content` (es. `2.1.0`).
- `Starter_Live_Annotator` (`render_block`, prio 20) abbina i valori dei blocchi all'HTML renderizzato (testo identico, link con lo stesso href, immagine con lo stesso file) e aggiunge `data-live="pN"`. Per i blocchi ACF legge `attrs.data`.
- Il registro dei campi viene passato al JS nel footer (`window.StarterLive`).
- `POST /wp-json/starter-live/v1/save` riscrive il blocco giusto e salva con `wp_update_post`: **WordPress crea una revisione a ogni salvataggio**, quindi si può tornare indietro dal backend.

## Sicurezza
- Solo utenti con `edit_post` sulla pagina (filtro `starter_live_can_edit` per restringere, es. a un ruolo "cliente").
- Nonce REST, controllo che il blocco al percorso indicato sia quello atteso, sanitizzazione per tipo di campo (HTML ridotto a b/i/u/a/br/sub/sup).
- Chi non è loggato non riceve nessun markup aggiuntivo.

## Limiti noti (v0.1)
- Funziona sulle pagine/articoli singoli (`is_singular`), non su archivi o pagina "Blog".
- Un testo non abbinabile all'HTML (trasformato dal tema) resta non modificabile da frontend.
- Blocchi riutilizzabili/pattern sincronizzati non sono modificabili da frontend.
