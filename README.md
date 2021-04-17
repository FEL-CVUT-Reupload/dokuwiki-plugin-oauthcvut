# dokuwiki-plugin-oauthcvut

Plugin do dokuwiki poskytující autentifikaci přes ČVUT účet a nové příkazy pro zobrazení dat z KOSu.

## Dokuwiki syntax
`{{course:COURSE,sem=SEM,merge}}`

- `COURSE` -- Úplný název předmětu nebo více předmětů oddělený `|`
	- například `B0B01LAG`, `B0B01MA1|B0B01MA1A`
- `sem=SEM` *(nepovinný)* -- Zobrazení specifické instance předmětu dle názvu semestru (ve výchozím stavu se jedná o aktuální semestr)
	- například `sem=B202`
- `merge` *(nepovinný)* -- Spojení více předmětů do jednoho (zobrazí se pouze unikátní hodnoty)

`{{student_courses}}` (pro stránky obsahující tento příkaz neplatí cache)

- bez parametrů
