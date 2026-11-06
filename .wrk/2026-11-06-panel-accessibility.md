# Sense CMS: przegląd dostępności i spójności panelu (6 listopada 2026)

Cel: www.sensecms.com. Testy wykonano w Edge na rzeczywistych stronach z włączonym JavaScriptem. Panel sprawdzono po zalogowaniu z uprawnieniami właściciela; logowanie i odzyskiwanie dostępu osobno, bez sesji. Konto testowe nie zmieniało treści, plików, wydarzeń ani ustawień witryny. Zmiany preferencji interfejsu dotyczyły wyłącznie tymczasowego profilu przeglądarki.

## Zakres i wynik

76 widoków lub stanów × PL/EN × szerokości 390/1440 px × jasny/ciemny motyw = **608 kombinacji**. Dla publicznych formularzy obie preferencje motywu mogą dawać ten sam widok. Po poprawkach powtarzano testy dotkniętych stanów. Ostatni wynik każdej kombinacji: poprawne załadowanie i język dokumentu, brak naruszeń zgłoszonych przez uruchomione reguły axe-core 4.13.0 i brak poziomego przewijania całej strony. Nie każda zmiana wymagała powtórzenia wszystkich niezwiązanych ekranów.

| Obszar | Przykłady sprawdzonych widoków i stanów |
| --- | --- |
| Dostęp i wspólna nawigacja | Logowanie, odzyskiwanie i ustawianie hasła, pulpit, profil, ustawienia, panel preferencji, mobilne menu i szybkie wyszukiwanie. |
| Redakcja | Listy stron i wpisów, nowe dokumenty i edycja istniejących, obieg redakcyjny, placówki i geolokalizacja, kategorie, nagłówek i stopka, SEO, kreator stron z otwartym widokiem struktury. |
| Media i formularze | Biblioteka mediów, otwarte okna przesyłania, folderów i szczegółów pliku, skrzynka zgłoszeń, ankiety i zakładki budowy, logiki i publikacji. |
| Wygląd i system | Motywy, wygląd, marketplace, rozszerzenia i ich zakładki, użytkownicy, zespoły, role, audyt, pamięć podręczna, e-mail, wyszukiwarka, dźwięki, CAPTCHA, języki, powiadomienia, aktualizacje, licencja. |
| AI, rozmowy i integracje | Asystent AI, baza wiedzy i jej zakładki, scenariusze, rozmowy i konfiguracja czatu, analityka, publikacje społecznościowe i dziewięć paneli dostawców. |
| Kalendarz | Widok podstawowy, agenda i otwarty formularz nowego wydarzenia. Kontrola nie tworzyła wydarzeń. |

Osobny test klawiaturą na www w PL i EN obejmował nawigację Tab i Shift+Tab w ustawieniach, Escape i powrót do wyzwalacza, wejście do mobilnego menu, przejście strzałkami z wyszukiwarki do wyniku i z powrotem, przejście do pola tytułu wydarzenia oraz nazwę wyboru poziomu nagłówka w edytorze. Przy szerokości 320 px i tekście 200% dokument zachował szerokość 320 px. W trzech oknach biblioteki mediów sprawdzono modalność, fokus po otwarciu, Tab oraz powrót po Escape w obu językach.

## Problemy usunięte

- Niewidoczny panel ustawień wyłączono z nawigacji klawiaturą. Otwarcie przenosi fokus do środka, a zamknięcie oddaje go przyciskowi. Wyszukiwarka przenosi rzeczywisty fokus na wyniki i informuje o ich liczbie.
- Okna przesyłania, szczegółów pliku i folderów korzystają z natywnych dialogów z nazwami. Przed poprawką fokus pozostawał za widocznym oknem; po poprawce można obsłużyć dialog bez myszy. Uzupełniono nazwy kontrolek, m.in. wyboru formatu tekstu, filtrów wiedzy, edycji ról i zespołów, wyboru pakietu i zamykania folderu. Ujednolicono opisy mediów i ich tłumaczenia z zachowaniem oryginalnych danych.
- Zmieniono kontrast statusów, zakładek, opisów, aktywnej nawigacji i stanów edytorów w obu motywach. Poprawiono widoczność fokusu, rozmiar kontrolek wyboru dokumentu, czytelność preferencji na telefonie i odnośniki pomijające treść poprzedzającą formularze uwierzytelniania.

## Granice kontroli

Reguły axe dla WCAG A/AA, nazwy i stany kontrolek, pomiary układu, inspekcja wyglądu oraz opisane testy klawiaturą nie stanowią certyfikacji WCAG. Testy mobilne korzystały z emulacji dotyku i rozmiaru okna; nie przeprowadzono sesji na fizycznym telefonie, w iOS/Safari ani z lektorem ekranu. Nie oceniano treści redakcyjnych, odrębnych paneli zewnętrznych dostawców, ramek osadzonych stron, wszystkich kombinacji danych lub wszystkich ról.

## Wdrożenie i odzyskiwanie

Zaktualizowano 12 unikalnych plików Core na www, bez migracji, zmian uprawnień i modyfikowania podpisanych pakietów. Po wdrożeniu porównano sumy SHA-256 z efektywną wersją każdego pliku. Apache, PHP-FPM, PostgreSQL i cron działały, publiczne logowanie zwróciło HTTP 200; dziennik błędów PHP nie urósł i w sprawdzonym dzienniku usług nie było nowych wpisów poziomu błędu. Tymczasowe sesje QA zakończono.

Dokładne manifesty plików i instrukcje odzyskiwania są dostępne w prywatnych zapisach wdrożenia.

Lokalnie przeszły kontrole składni zmienionego PHP i JavaScriptu, testy PL/EN, 22 testy edytora wpisów, 28 testów nawigacji, 15 kontroli powiadomień, 46 kontroli pakietu instalatora, 37 kontroli instalatora WWW oraz testy klawiaturowe z renderowanym widokiem biblioteki. Wdrożenie nie korzystało z instalatora.
