# 8.1.2
* Fehlende shippingOrderAddress-Assoziation im SubscriptionQuickpayService hinzugefügt

# 8.1.1
* Die Logik für wiederkehrende Zahlungen wurde überarbeitet, um Initialzahlungen von Folgezahlungen bei Abonnements zu unterscheiden.

# 8.1.0
* Behebt ein Problem, das das Stornieren von Bestellungen in Quickpay verhinderte.

# 8.0.3
* StateMachineState-Verknüpfung hinzugefügt, um den Fehler "Call to a member function getTechnicalName() on null" zu vermeiden.

# 8.0.2
* Ändern Sie den CartPersister-Typhinweis in Abstract TypePersister im CartOrderRoute Decorator, um Dekoration zu ermöglichen

# 8.0.1
* Problem behoben, das den Fehler "Call to a member function getTechnicalName() on null" verursachte.

# 8.0.0
* Shopware 6.6 kompatibel.

# 7.1.0
* Behebt ein Problem, das das Stornieren von Bestellungen in Quickpay verhinderte.

# 7.0.8
* Ein Problem behoben, bei dem die neue Abonnementbestellung nicht korrekt abgerufen wurde.

# 7.0.7
* Erneuerungsfunktion für Abonnement-Bestellungen hinzugefügt.

# 7.0.6
* Ändern Sie den CartPersister-Typhinweis in Abstract TypePersister im CartOrderRoute Decorator, um Dekoration zu ermöglichen

# 7.0.5
* Ungültige Validierung des Rückerstattungsstatus behoben

# 7.0.4
* Ändern Sie den Ladestatus, wenn keine Quickpay-Antwort erfolgt, um das unendliche Laden im Admin-Panel zu beheben.

# 7.0.3
* Verbesserte bedingte Überprüfung des Steuersatzes, um die Sicherheit gegen potenzielle Nullzeiger-Ausnahmen zu gewährleisten.

# 7.0.2
* Zusätzlicher Service zur Abwicklung der Rückerstattung (Für Entwickler)
 
# 7.0.1
* Auf der Registerkarte „QuickPay-Zahlung“ der Bestellung wurden Informationen zur Zahlungsmarke hinzugefügt

# 7.0.0
* Shopware 6.5 kompatibel

# 6.2.4
* Leistungsoptimierungen bei der Ereignisbehandlung
* Einstellungen zum Deaktivieren der Erfassung und Stornierung von Zahlungen in QuickPay hinzugefügt

# 6.2.3
* Verbessern Sie die Ausnahmeprotokolle, schließen Sie die sw_status_code-Antwort auf die Quickpay-Abschlusstransaktionsanforderung ein

# 6.2.2
* Entfernen Sie die Verwendung von @RouteScope für Shopware 6.5-Kompatibilität

# 6.2.1
* Gehen Sie zurück zur Checkout-Bestätigungsseite, wenn Sie im Zahlungsfenster stornieren

# 6.2.0
* Interne API-Änderungen, um mehr Anpassbarkeit zu ermöglichen

# 6.1.8
* Anyday Zahlungsmethode hinzugefügt
* Die Vipps-Zahlungsmethode wurde von vipps zu vippspsp geändert, was Vipps über Quickpay ist

# 6.1.7
* Veraltete Ausnahmemeldung behoben

# 6.1.6
* Vipps als Zahlungsmethode hinzugefügt

# 6.1.5
* Bugfix beim Aktualisieren von Plugin und Abonnement customField bereits vorhanden 

# 6.1.4
* Bugfix für Swish Payment Callback und Shopware State Transition

# 6.1.3
* Aktualisieren Sie die Erfassungsfunktion, um immer die neuesten Informationen von Quickpay zu erhalten

# 6.1.2
* Behoben, sodass Statusänderungen bei Nicht-Quickpay-Zahlungen keine Quickpay-Funktionen auslösen

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
