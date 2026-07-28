<?php
/**
 * Normalized T2med content seed.
 *
 * @package Leadwerk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Leadwerk_T2med_Seed {
	public static function pages() {
		return array(
			'nw-t2med-home-v2'  => self::home(),
			'nw-danke-v1'       => array(
				'page_eyebrow'    => 'Nachricht gesendet',
				'page_headline'   => 'Vielen Dank für deine Nachricht!',
				'page_text'       => 'Wir haben deine Nachricht erhalten und melden uns zeitnah bei dir. Unser Team freut sich darauf, dich und dein Anliegen kennenzulernen.',
				'page_cta_label'  => 'Zur Startseite',
				'page_cta_target' => array(
					'source_key' => 'nw-t2med-home-v2',
					'anchor'     => '',
				),
			),
			'nw-404-v1'         => array(
				'page_eyebrow'          => '404 · Seite nicht gefunden',
				'page_headline'         => 'Diese Seite ist vom Kurs abgekommen.',
				'page_text'             => 'Die aufgerufene Adresse existiert nicht oder wurde verschoben. Über die Startseite findest du schnell zurück.',
				'page_cta_label'        => 'Zur Startseite',
				'page_cta_target'       => array(
					'source_key' => 'nw-t2med-home-v2',
					'anchor'     => '',
				),
				'page_secondary_label'  => 'T2med-Wechsel vorprüfen',
				'page_secondary_target' => array(
					'source_key' => 'nw-t2med-home-v2',
					'anchor'     => 'vorpruefung',
				),
			),
			'nw-impressum-v1'   => array(
				'page_eyebrow'  => 'Rechtliches',
				'page_headline' => 'Impressum',
				'page_content'  => self::imprint(),
			),
			'nw-datenschutz-v1' => array(
				'page_eyebrow'  => 'Bei uns sind Ihre Daten sicher',
				'page_headline' => 'Datenschutzerklärung',
				'page_content'  => self::privacy(),
			),
		);
	}

	public static function options() {
		return array(
			'company_name'        => 'die netzwerft GmbH',
			'company_address'     => "Otto-Dix-Straße 9\n76275 Ettlingen",
			'company_phone'       => '07243 350600',
			'company_email'       => 'info@dienetzwerft.de',
			'footer_description'  => 'Medizin-IT aus einer Hand – von T2med bis Telefonie. Persönliche Betreuung für Arztpraxen im Raum Karlsruhe, Ettlingen und Speyer.',
			'notification_email'  => 'info@dienetzwerft.de',
			'legal_review_status' => 'pending',
			'legal_review_notes'  => 'Seed basiert auf den am 27.07.2026 öffentlich erreichbaren Seiten. Geschäftsführung, WPForms, YouTube, Consent, Hosting, Löschfristen und eingesetzte Dienste rechtlich prüfen.',
			'header_menu'         => array(
				self::link( 'Warum wechseln', 'nw-t2med-home-v2', 'warum-wechseln' ),
				self::link( 'Leistungen', 'nw-t2med-home-v2', 'leistungen' ),
				self::link( 'Ablauf', 'nw-t2med-home-v2', 'ablauf' ),
				self::link( 'FAQ', 'nw-t2med-home-v2', 'faq' ),
			),
			'footer_menu'         => array(
				self::link( 'Startseite', 'nw-t2med-home-v2', '' ),
				self::link( 'Vorprüfung', 'nw-t2med-home-v2', 'vorpruefung' ),
				self::link( 'Impressum', 'nw-impressum-v1', '' ),
				self::link( 'Datenschutz', 'nw-datenschutz-v1', '' ),
			),
		);
	}

	private static function link( $label, $source_key, $anchor ) {
		return array(
			'label'  => $label,
			'target' => array(
				'source_key' => $source_key,
				'anchor'     => $anchor,
			),
		);
	}

	private static function home() {
		return array(
			'seo_focus_keyphrase'     => 'T2med Wechsel Karlsruhe',
			'seo_title'               => 'T2med Wechsel Karlsruhe | Praxis-IT aus einer Hand',
			'seo_description'         => 'T2med Wechsel Karlsruhe: die netzwerft begleitet Arztpraxen sicher bei Praxissoftware, IT, Telefonie, Migration und laufender Betreuung.',
			'hero_eyebrow'            => 'T2med & Praxis-IT im Raum Karlsruhe',
			'hero_title'              => 'Stressfrei zu T2med wechseln –',
			'hero_title_bold'         => 'mit Praxis-IT aus einer Hand.',
			'hero_text'               => 'die netzwerft begleitet Arztpraxen beim Softwarewechsel, bei IT-Modernisierung, Telefonie und laufender Betreuung – klar geplant, persönlich begleitet und auf den Praxisalltag abgestimmt.',
			'hero_cta_label'          => 'T2med-Wechsel vorprüfen lassen',
			'hero_trust'              => 'Spezialisiert auf Arztpraxen, T2med, TI/KIM/KBV und sichere Praxisprozesse.',
			'hero_image_alt'          => 'T2med Wechsel Karlsruhe – Praxissoftware auf Desktop, Tablet und Smartphone',
			'hero_image_focus'        => '50% 50%',
			'problem_eyebrow'         => 'Der Praxisalltag',
			'problem_title'           => 'Wenn Software, IT und Telefonie',
			'problem_title_bold'      => 'den Praxisalltag bremsen.',
			'problem_intro'           => 'Viele Praxen kennen das: Die Technik soll unterstützen, kostet aber täglich Zeit und Nerven. Das muss nicht so bleiben.',
			'problem_items'           => array(
				array(
					'title' => 'Praxissoftware kostet Zeit',
					'text'  => 'Langsame Masken, umständliche Wege und unklare Abläufe bremsen Anmeldung, Dokumentation und Abrechnung.',
				),
				array(
					'title' => 'Support ist schwer erreichbar',
					'text'  => 'Wenn etwas klemmt, hängen Sie in der Warteschleife – statt schnell eine klare Antwort von einem festen Ansprechpartner zu bekommen.',
				),
				array(
					'title' => 'Telefonie und IT laufen getrennt',
					'text'  => 'Verschiedene Dienstleister für Software, Netzwerk und Telefon – und niemand fühlt sich zuständig, wenn es an den Schnittstellen hakt.',
				),
				array(
					'title' => 'Angst vor Wechselchaos',
					'text'  => 'Datenmigration, Ausfallzeiten, ein neues System für das Team: Die Sorge, dass der Wechsel den laufenden Betrieb blockiert, hält viele zurück.',
				),
			),
			'solution_eyebrow'        => 'Die Lösung',
			'solution_title'          => 'T2med wechseln,',
			'solution_title_bold'     => 'ohne den Praxisbetrieb zu blockieren.',
			'solution_intro'          => 'Wir installieren nicht einfach Software. Wir begleiten den Wechsel ganzheitlich – von der ersten Einordnung bis zum stabilen Betrieb. So bleibt Ihre Praxis handlungsfähig, während im Hintergrund alles vorbereitet wird.',
			'solution_cta_label'      => 'T2med-Wechsel vorprüfen lassen',
			'solution_items'          => array(
				array(
					'title' => 'Vorprüfung der Ausgangssituation',
					'text'  => 'Wir schauen uns Software, Abläufe und Technik an, bevor wir etwas verändern.',
				),
				array(
					'title' => 'IT-Check',
					'text'  => 'Wir prüfen, ob Netzwerk, Hardware und Sicherheit für T2med bereit sind.',
				),
				array(
					'title' => 'T2med-Einführung',
					'text'  => 'Einrichtung und Schulung, damit Ihr Team vom ersten Tag an sicher arbeitet.',
				),
				array(
					'title' => 'Datenmigration & Abstimmung',
					'text'  => 'Bestehende Daten werden strukturiert übernommen, planbar und nachvollziehbar.',
				),
				array(
					'title' => 'Telefonie und Arbeitsplätze',
					'text'  => 'Erreichbarkeit und Ausstattung werden mitgedacht, nicht nachträglich geflickt.',
				),
				array(
					'title' => 'Laufende Betreuung',
					'text'  => 'Auch nach dem Go-live bleiben wir Ihr fester Ansprechpartner.',
				),
			),
			'video_eyebrow'           => 'Praxissoftware im Einsatz',
			'video_title'             => 'T2med im Praxisalltag –',
			'video_title_bold'        => 'verständlich eingeführt, technisch sauber begleitet.',
			'video_intro'             => 'Damit neue Software wirklich entlastet, muss sie zur IT, zur Telefonie und zu den Abläufen der Praxis passen. Genau hier verbindet die netzwerft Softwareverständnis mit Praxis-IT.',
			'video_items'             => array(
				array( 'text' => 'Klarer Wechselprozess – jeder Schritt geplant und abgestimmt' ),
				array( 'text' => 'Persönliche Begleitung durch einen festen Ansprechpartner' ),
				array( 'text' => 'IT, Telefonie und Betrieb von Anfang an mitgedacht' ),
			),
			'video_cta_label'         => 'T2med-Wechsel vorprüfen lassen',
			'video_youtube_id'        => 'nNbMb5gRFEc',
			'video_title_attr'        => 'T2med im Praxisalltag – Produktdemo',
			'video_poster_alt'        => 'T2med Wechsel Karlsruhe – Vorschau der Praxissoftware',
			'video_external_label'    => 'Mehr über die Praxissoftware auf der offiziellen T2med-Website',
			'video_external_url'      => 'https://t2med.de/',
			'services_eyebrow'        => 'Leistungsbausteine',
			'services_title'          => 'Alles, was den Wechsel',
			'services_title_bold'     => 'wirklich trägt.',
			'services_intro'          => 'T2med ist der Einstieg – wir denken Software, IT, Telefonie und Sicherheit gemeinsam.',
			'services_items'          => array(
				array(
					'icon_key' => 'software',
					'title'    => 'T2med-Einführung & Wechselbegleitung',
					'text'     => 'Von der Vorprüfung über Migration und Schulung bis zum sicheren Start – begleitet statt sich selbst überlassen.',
				),
				array(
					'icon_key' => 'server',
					'title'    => 'Praxis-IT & Arbeitsplätze',
					'text'     => 'Rechner, Server und Peripherie sauber eingerichtet – so, dass Ihr Team ohne Reibungsverluste arbeiten kann.',
				),
				array(
					'icon_key' => 'phone',
					'title'    => 'Telefonanlage & Erreichbarkeit',
					'text'     => 'Warteschleifen, Fax-to-Mail und moderne Telefonie – damit Patienten Sie zuverlässig erreichen.',
				),
				array(
					'icon_key' => 'server',
					'title'    => 'Netzwerk, WLAN & Infrastruktur',
					'text'     => 'Stabile Netze und WLAN als Fundament – die Basis, auf der Software und Telefonie zuverlässig laufen.',
				),
				array(
					'icon_key' => 'security',
					'title'    => 'IT-Sicherheit, TI, KIM, KBV & DSGVO',
					'text'     => 'Telematikinfrastruktur, sichere Kommunikation und Datenschutz – nach den Anforderungen für Arztpraxen mitgedacht.',
				),
				array(
					'icon_key' => 'support',
					'title'    => 'Laufende Betreuung & Support',
					'text'     => 'Fester Ansprechpartner, schnelle Hilfe und Weiterentwicklung – statt anonymer Hotline und ständig neuer Kontakte.',
				),
			),
			'process_eyebrow'         => 'Ablauf',
			'process_title'           => 'So läuft der Wechsel ab –',
			'process_title_bold'      => 'geführt und planbar.',
			'process_intro'           => 'Sie müssen den Wechsel nicht selbst steuern. Wir führen Sie Schritt für Schritt durch den Prozess.',
			'process_items'           => array(
				array(
					'number' => '1',
					'title'  => 'Erstgespräch & Vorprüfung',
					'text'   => 'Wir hören zu, verstehen Ihre Situation und ordnen ein, ob und wie ein Wechsel sinnvoll ist.',
				),
				array(
					'number' => '2',
					'title'  => 'Wechselziel & Abläufe klären',
					'text'   => 'Gemeinsam definieren wir, was der Wechsel erreichen soll und wie Ihre Praxis arbeitet.',
				),
				array(
					'number' => '3',
					'title'  => 'Technisches Konzept erstellen',
					'text'   => 'Wir planen Software, IT, Telefonie und Sicherheit als ein stimmiges Gesamtbild.',
				),
				array(
					'number' => '4',
					'title'  => 'Umsetzung koordinieren',
					'text'   => 'Migration, Einrichtung und Schulung – abgestimmt auf Ihren Praxisbetrieb.',
				),
				array(
					'number' => '5',
					'title'  => 'Praxis stabil betreuen',
					'text'   => 'Nach dem Go-live bleiben wir an Ihrer Seite – mit Support und Weiterentwicklung.',
				),
			),
			'trust_eyebrow'           => 'Warum die netzwerft',
			'trust_title'             => 'Vertrauen entsteht durch',
			'trust_title_bold'        => 'Erfahrung und Nähe.',
			'trust_intro'             => 'Wir sind kein anonymes IT-Systemhaus, sondern ein spezialisierter Partner für Arztpraxen im Raum Karlsruhe.',
			'trust_items'             => array(
				array(
					'icon_key' => 'security',
					'title'    => 'Spezialisiert auf Arztpraxen',
					'text'     => 'Wir kennen den Praxisalltag und die Anforderungen medizinischer Abläufe.',
				),
				array(
					'icon_key' => 'software',
					'title'    => 'Software, IT und Telefonie aus einer Hand',
					'text'     => 'Ein Partner statt vieler Dienstleister – ohne Schnittstellen-Lücken.',
				),
				array(
					'icon_key' => 'support',
					'title'    => 'Persönliche Betreuung statt anonymer Hotline',
					'text'     => 'Feste Ansprechpartner, die Ihre Praxis und ihre Technik kennen.',
				),
				array(
					'icon_key' => 'check',
					'title'    => 'Erfahrung mit T2med und Praxisprozessen',
					'text'     => 'Wir verbinden Softwareverständnis mit echtem Praxis-Know-how.',
				),
				array(
					'icon_key' => 'security',
					'title'    => 'TI, KIM, KBV und DSGVO mitgedacht',
					'text'     => 'Sicherheit und Vorgaben sind von Anfang an Teil der Planung.',
				),
				array(
					'icon_key' => 'check',
					'title'    => 'Planbare Einführung statt Abstimmungschaos',
					'text'     => 'Klare Schritte, klare Verantwortlichkeiten, klarer Zeitplan.',
				),
			),
			'trust_stats'             => array(
				array(
					'value' => '200+',
					'label' => 'betreute Arztpraxen',
				),
				array(
					'value' => '3',
					'label' => 'Standorte: Karlsruhe · Ettlingen · Speyer',
				),
				array(
					'value' => '1',
					'label' => 'Ansprechpartner für die komplette Praxis-IT',
				),
			),
			'qualify_eyebrow'         => 'Vorprüfung',
			'qualify_title'           => '3 kurze Fragen –',
			'qualify_title_bold'      => 'damit das Erstgespräch direkt Substanz hat.',
			'qualify_intro'           => 'Kein Formular-Marathon. Nur das, was wir brauchen, um Sie richtig einzuordnen.',
			'qualify_situation_label' => '1. Welche Situation trifft zu?',
			'qualify_situation_items' => self::choices( array( 'Softwarewechsel / T2med', 'Praxisgründung', 'Praxisübernahme', 'Laufende Betreuung' ) ),
			'qualify_location_label'  => '2. In welcher Region befindet sich die Praxis?',
			'qualify_location_holder' => 'PLZ oder Ort, z. B. 76275 Ettlingen',
			'qualify_start_label'     => '3. Wann soll das Projekt starten?',
			'qualify_start_items'     => self::choices( array( 'sofort', '1–3 Monate', '3–6 Monate', 'später' ) ),
			'qualify_scope_label'     => 'Optional: Geht es nur um T2med oder auch um IT, Telefonie und Betreuung?',
			'qualify_scope_items'     => self::choices( array( 'Nur T2med', 'T2med + IT & Telefonie', 'Noch unklar' ) ),
			'qualify_cta_label'       => 'T2med-Wechsel vorprüfen lassen',
			'qualify_note'            => 'Unverbindlich & kostenlos. Wir melden uns persönlich bei Ihnen.',
			'faq_eyebrow'             => 'FAQ',
			'faq_title'               => 'Häufige',
			'faq_title_bold'          => 'Fragen zum Wechsel',
			'faq_items'               => array(
				array(
					'question' => 'Wie aufwendig ist ein Wechsel zu T2med?',
					'answer'   => 'Der sichtbare Aufwand für Ihre Praxis bleibt gering, weil wir den Wechsel strukturiert vorbereiten. Vorprüfung, Migration und Schulung werden so geplant, dass Ihr Team Schritt für Schritt mitgenommen wird.',
				),
				array(
					'question' => 'Kann der Praxisbetrieb während der Umstellung weiterlaufen?',
					'answer'   => 'Ja. Wir stimmen die Umsetzung auf Ihren Praxisalltag ab und wählen Zeitpunkte, die den Betrieb möglichst wenig belasten. Ziel ist ein Wechsel ohne Chaos – planbar und begleitet.',
				),
				array(
					'question' => 'Begleitet die netzwerft auch IT und Telefonie?',
					'answer'   => 'Ja. Wir denken Praxissoftware, IT, Netzwerk, Telefonie und Sicherheit gemeinsam – damit alles zuverlässig ineinandergreift.',
				),
				array(
					'question' => 'Ist die Betreuung auf Arztpraxen spezialisiert?',
					'answer'   => 'Ja. Wir sind auf medizinische Praxen spezialisiert und kennen die Anforderungen an Abläufe, Datenschutz und Telematikinfrastruktur.',
				),
				array(
					'question' => 'Für welche Region ist die netzwerft Ansprechpartner?',
					'answer'   => 'Unser Fokus liegt auf dem Raum Karlsruhe, Ettlingen, Speyer und Umgebung. Persönliche Betreuung vor Ort ist für uns ein zentraler Baustein.',
				),
			),
			'final_title'             => 'Bereit für einen',
			'final_title_bold'        => 'klar geplanten T2med-Wechsel?',
			'final_text'              => 'Wir prüfen gemeinsam, wo Ihre Praxis steht und welche nächsten Schritte sinnvoll sind.',
			'final_cta_label'         => 'T2med-Wechsel vorprüfen lassen',
			'final_contact_prefix'    => 'Lieber direkt sprechen?',
			'modal_eyebrow'           => 'T2med-Wechsel',
			'modal_title'             => 'Ihre Angaben für ein Erstgespräch mit Substanz.',
			'modal_intro'             => 'Die Vorprüfung wird übernommen. Ergänzen Sie nur noch Ihre Kontaktdaten.',
		);
	}

	private static function choices( $labels ) {
		return array_map(
			static function ( $label ) {
				return array(
					'label' => $label,
					'value' => sanitize_title( $label ),
				);
			},
			$labels
		);
	}

	private static function imprint() {
		return '<h2>Angaben gemäß § 5 DDG</h2>
<p><strong>die netzwerft GmbH</strong><br>Otto-Dix-Straße 9<br>76275 Ettlingen</p>
<p>Telefon: <a href="tel:+497243350600">07243 350600</a><br>E-Mail: <a href="mailto:info@dienetzwerft.de">info@dienetzwerft.de</a></p>
<p>Gesetzlicher Geschäftsführer: Simon Fuß</p>
<p>Handelsregister: HRB 733665<br>Registergericht: Amtsgericht Mannheim</p>
<h2>Umsatzsteuer-ID</h2>
<p>Umsatzsteuer-Identifikationsnummer gemäß § 27a Umsatzsteuergesetz: DE325233404</p>
<h2>Berufshaftpflichtversicherung</h2>
<p>Hiscox SA, Arnulfstraße 31, 80636 München, Deutschland</p>
<h2>Streitschlichtung</h2>
<p>Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>
<h2>Haftung für Inhalte und Links</h2>
<p>Wir sind für eigene Inhalte nach den allgemeinen Gesetzen verantwortlich. Für externe Inhalte verlinkter Anbieter übernehmen wir keine Gewähr. Bei Bekanntwerden einer konkreten Rechtsverletzung entfernen wir betroffene Inhalte oder Links unverzüglich.</p>
<h2>Urheberrecht</h2>
<p>Die durch die Seitenbetreiber erstellten Inhalte unterliegen dem deutschen Urheberrecht. Eine Verwertung außerhalb der gesetzlichen Grenzen bedarf der vorherigen Zustimmung.</p>';
	}

	private static function privacy() {
		return '<p><em>Stand: 27. Juli 2026. Dieser importierte Ausgangstext muss vor dem Livegang rechtlich und gegen die tatsächlich eingesetzten Dienste geprüft werden.</em></p>
<h2>1. Datenschutz auf einen Blick</h2>
<p>Personenbezogene Daten sind Informationen, mit denen Sie persönlich identifiziert werden können. Wir verarbeiten Daten, die Sie uns mitteilen, sowie technisch erforderliche Daten, die beim Aufruf dieser Website entstehen.</p>
<h2>2. Verantwortliche Stelle</h2>
<p><strong>die netzwerft GmbH</strong><br>Otto-Dix-Straße 9<br>76275 Ettlingen<br>Telefon: 07243 350600<br>E-Mail: <a href="mailto:datenschutz@dienetzwerft.de">datenschutz@dienetzwerft.de</a></p>
<p>Die Angaben zur Geschäftsführung sind mit dem aktuellen Handelsregister und dem Impressum abzugleichen.</p>
<h3>Datenschutzbeauftragte</h3>
<p>Luise Thom<br>E-Mail: <a href="mailto:datenschutz@dienetzwerft.de">datenschutz@dienetzwerft.de</a></p>
<h2>3. Hosting und Server-Log-Dateien</h2>
<p>Der Hostinganbieter kann Browsertyp, Betriebssystem, Referrer, Hostname, Zeitpunkt und IP-Adresse in Server-Log-Dateien verarbeiten. Die Verarbeitung dient der sicheren und fehlerfreien Bereitstellung der Website auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO. Anbieter und konkrete Löschfristen sind vor Veröffentlichung einzutragen.</p>
<h2>4. Kontaktformular mit WPForms</h2>
<p>Wenn Sie das Formular verwenden, verarbeiten wir Situation, Standort, Projektstart, optionalen Leistungsumfang, Name, E-Mail-Adresse, Telefonnummer, Praxisname, Nachricht und Ihre Einwilligung zur Bearbeitung der Anfrage. Zweck ist die Beantwortung und Durchführung vorvertraglicher Maßnahmen. Rechtsgrundlage ist je nach Anfrage Art. 6 Abs. 1 lit. a oder b DSGVO. Die Daten bleiben gespeichert, bis der Zweck entfällt, Sie eine wirksame Löschung verlangen oder gesetzliche Pflichten entgegenstehen.</p>
<p>Die konkrete WPForms-Version, Speicherung von Einträgen, Spam-Schutz, E-Mail-Übermittlung, Hosting und Auftragsverarbeitung müssen vor Livegang dokumentiert werden.</p>
<h2>5. YouTube im erweiterten Datenschutzmodus</h2>
<p>Das eingebundene Video wird erst nach Ihrer aktiven Auswahl geladen. Dabei wird eine Verbindung zu <code>youtube-nocookie.com</code> hergestellt; Google kann dennoch technische Daten verarbeiten. Ohne Ihre Auswahl wird kein Player geladen. Rechtsgrundlage ist Ihre Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO, sofern der eingesetzte Consent-Mechanismus dies entsprechend steuert.</p>
<h2>6. Lokale Schriftarten</h2>
<p>Montserrat wird lokal vom eigenen Server ausgeliefert. Beim Seitenaufruf wird dafür keine Verbindung zu Google Fonts aufgebaut.</p>
<h2>7. Ihre Rechte</h2>
<p>Sie haben im gesetzlichen Rahmen Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch. Einwilligungen können mit Wirkung für die Zukunft widerrufen werden. Außerdem besteht ein Beschwerderecht bei einer Datenschutzaufsichtsbehörde.</p>
<h2>8. Verschlüsselung und Aktualität</h2>
<p>Diese Website soll ausschließlich verschlüsselt per TLS übertragen werden. Wir passen diese Datenschutzerklärung an, wenn sich eingesetzte Dienste oder rechtliche Anforderungen ändern.</p>';
	}
}
