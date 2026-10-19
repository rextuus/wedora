# Produktkonzept – Wedora

**Arbeitsname:** Wedora  
**Claim-Vorschlag:** *Your wedding. Your people. Your memories.*

## Vision
Wedora ist eine mobile-first Webplattform, mit der Hochzeitsgäste gemeinsame Erinnerungen durch Fotos, Videos, Challenges, Nachrichten und ein digitales Gästebuch schaffen. Die Anwendung soll Begegnungen unterstützen, nicht Gäste dauerhaft an Bildschirme binden. Sie ist für viele Hochzeiten wiederverwendbar; jede Hochzeit besitzt eigene Konfigurationen, Inhalte, Zugangsregeln und Module.

## Produktprinzipien
- Browserzugriff per QR-Code/Link, keine native App nötig.
- Kurze, bewusste Interaktionen statt algorithmischem Endlos-Feed.
- Module, Sichtbarkeit, Freigabe und Lebenszyklus pro Hochzeit konfigurierbar.
- Zentrale serverseitige Rechteprüfung.
- Mobile-first und gestalterisch elegant, warm und zurückhaltend.

## Rollen
- **Plattformadministration:** verwaltet Hochzeiten, globale Grenzen, Speicher, Rollen und Löschung.
- **Hochzeitsadministration:** erhält nur explizit erlaubte Rechte für Konfiguration, Moderation, Export und Lebenszyklus.
- **Gast:** tritt per konfiguriertem Zugangsweg bei, legt einen Anzeigenamen fest und sieht/beiträgt Inhalte gemäß Berechtigungen.

## Zugang und Gastidentität
Zugangsmethoden können kombiniert werden: offener Link/QR, gemeinsames Hochzeitspasswort und persönliche widerrufbare Einladungslinks. Für den MVP ist kein klassisches Konto mit E-Mail und Passwort erforderlich. Eine zufällige Gastidentität wird lokal im Browser gespeichert; Browserdatenverlust oder Gerätewechsel können sie verlieren lassen.

## Module
- **Foto- und Videogalerie:** Smartphone-Upload, Mehrfachauswahl, Fortschritt, Fehlerbehandlung, Wiederholung, Likes, Moderation und Export.
- **Challenges:** z. B. First Drink, First Dance, New Friends, Dancefloor Madness, Nachricht an das Brautpaar und benutzerdefinierte Aufgaben. Punkte und Ranglisten optional.
- **Digitales Gästebuch:** Text-, Foto- und Videobeiträge sowie private Videonachrichten.
- **Spätere Erweiterungen:** Umfragen, gemeinsame Ziele, Hochzeits-Bingo und persönliche Erinnerungsübersicht.

## Sichtbarkeit und Veröffentlichung
Sichtbarkeit ist unabhängig vom Veröffentlichungsstatus:
- alle Gäste;
- Brautpaar/Administratoren;
- eigener Gast;
- später optional benutzerdefinierte Empfängergruppen.

Veröffentlichung: sofort, erst nach Freigabe oder sofort mit nachträglicher Moderation. Meldefunktion für Gäste vorsehen.

## Verbindliche Designreferenz: Google Stitch
Die bereits in Google Stitch erstellten und vom Auftraggeber bestätigten Designvorlagen sind die maßgebliche visuelle Referenz. Die aktuelle Richtung ist warm, elegant und zurückhaltend (u. a. Elfenbein, Salbei/Oliv, Anthrazit und Serifenschriften für Überschriften). Vor der Implementierung sind die tatsächlich bereitgestellten Stitch-Screens, Links und Assets zu sichten. Layouts, Typografie, Farben, Abstände, Komponenten und responsive Verhaltensweisen werden daraus abgeleitet. Das Design darf nicht ohne Rücksprache durch ein generisches Admin-Theme ersetzt werden. Wenn die Vorlagen nicht zugänglich sind, muss Claude Code nach den Links/Exporten fragen, statt so zu tun, als hätte es sie gesehen. Stitch ist eine Designreferenz, keine fertige Anwendung; Logik, Berechtigungen, Barrierefreiheit, Zustände und Tests sind separat umzusetzen.

## Lebenszyklus
Pro Modul konfigurierbar: Ende von Challenges/Umfragen, Uploadschluss, Archivmodus, Export, Aufbewahrungsfrist und endgültige Löschung. Archivieren und Löschen sind getrennt; Archivierung löscht keine Medien automatisch.

## Medien und Kostenkontrolle
Originale und optimierte Varianten werden getrennt behandelt. Pro Hochzeit gibt es konfigurierbare Speicher- und Uploadlimits. Formate, Dateigröße und Videolänge sind zu begrenzen. Export, Speicherübersicht und vollständige Löschung sind erforderlich. Selbst gehostete Dienste vermeiden bestimmte Anbietergebühren, verursachen aber Kosten für Server, Speicher, Bandbreite, Backups, Monitoring und Wartung.

## Administration
Dashboard, Hochzeiten, Module, Design, Challenges, Zugangsregeln, Rollen, Moderation, Speicherübersicht, Export, Archivierung und Löschung.

## MVP
1. Hochzeiten und Konfiguration
2. Zugang per Link/QR und optional Passwort
3. Gastidentität und Anzeigename
4. Foto-Upload, Galerie und Moderation
5. Video-Upload/Wiedergabe nach erfolgreichem Sicherheitstest
6. Gästebuch und grundlegende Challenges
7. Speicherübersicht, Export und Lebenszyklus-Basics
8. responsive Umsetzung anhand der Stitch-Designs
9. Tests, Logs, Fehlerbehandlung und Sicherheitsgrundlagen
