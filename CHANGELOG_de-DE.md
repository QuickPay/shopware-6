# 6.1.1
* API-Konfigurationstest-Schaltfläche korrigiert

# 6.1.0
* Googlepay und Applepay als Zahlungsmethode hinzugefügt
* Konfiguration zum Ausblenden von Zahlungsmethoden, die im aktuellen User-Agent nicht unterstützt werden

# 6.0.0
* Abonnements für Quickpay hinzugefügt

# 5.2.0

* Paypal als Zahlungsmethode hinzugefügt
* Das Quickpay-Zahlungsfenster zeigt jetzt nur alternative Zahlungsmethoden an, die im aktuellen Verkaufskanal aktiviert sind

# 5.1.3

* Fehlerbehebungen für die Swish-Zahlung

# 5.1.2

* Änderungen bei der Swish-Zahlung
  * Da es sich bei Swish um eine Banküberweisung handelt, erfolgt die Erfassung beim Abschluss der Transaktion.
  * Der Zahlungsstatus in Shopware wird direkt auf Bezahlt aktualisiert (Autorisiert wird übersprungen), da die Erfassung bereits erfolgt ist.
  * Wenn wir versuchen, eine Swish-Zahlung zu erfassen, überspringen wir die Erfassung und aktualisieren Shopware-Status für den Versand- und Bestellstatus.
    * Versandstatus: Versendet
    * Bestellstatus: Fertig

# 5.1.1

* Kompatibilität mit Shopware 6.4.9.0

# 5.1.0

* Swish als Zahlungsmethode hinzugefügt  

# 5.0.11

* Ein Problem wurde behoben, bei dem die manuelle Zahlungserfassung fehlschlug, wenn der Bestellstatus "Autorisiert" war.

# 5.0.10

* Fixed an issue where payment did not finalize when receiving callback from Quickpay

# 5.0.9

* Fix für doppelte Token-Ungültigkeitserklärung bei Verwendung von MobilePay und Mastercard
* Shopware 6.4.5.x-Kompatibilität

# 5.0.7

* Stellen Sie sicher, dass der Warenkorb nach erfolgreicher Zahlung auf Shopware 6.4.4.1 gelöscht wird

# 5.0.6

* Problem mit doppelter Token-Ungültigkeitserklärung in Shopware 6.4.3.0 behoben

# 5.0.5

* Verwenden Sie verkaufskanalspezifische Einstellungen, um die Zahlung zu stornieren
* Versuchen Sie nicht, autorisierte Zahlungen zu stornieren

# 5.0.3

* Kleinere Anpassungen und Aufräumarbeiten. PHP 7.2 wird nicht mehr unterstützt

# 5.0.2

* API-Test von API & privatem Schlüssel
* Fehler beim Abbrechen der Bestellung behoben

# 5.0.0

* Shopware 6.4 Kompatibilität
* Rückruf-Check
* Zahlungs- und Versandautomatisierungen

# 3.2.0

* Viabill fügte hinzu
* Klarna fügte hinzu
* Legen Sie die Sprache des Zahlungsfensters basierend auf der Sprache des Vertriebskanals fest
* Bessere Rückrufbearbeitung

# 3.0.4

* API-Testschaltfläche korrigieren

# 3.0.3

* Die Notwendigkeit für die MobilePay-ID in der Administratorkonfiguration wurde entfernt
