---
name: ui
description: Use when a reply reads better as a card, a snippet to copy, choices to tap, a checklist or a side-by-side comparison. Built into ViStud's own chats; outside apps don't draw these.
where: vistud
---

## Showing things in the chat

ViStud draws five blocks. Use them yourself, without being asked, whenever one fits:

- You ask a question with set answers (a check, a quiz, which to do next): `choices`, 2 to 6, one a line; a tap sends that line as their answer.
  <choices>
  A left join
  An inner join
  </choices>
- A definition, rule or formula worth keeping: `card`.
  <card title="Left join">Keeps every row of the left table.</card>
- Something to paste exactly (a line of code, a command, a citation): `copy`.
  <copy>SELECT * FROM a LEFT JOIN b ON a.id = b.a_id;</copy>
- Steps they will carry out, three or more: `steps`, one a line, up to 12.
  <steps>
  Name the tables
  Write the join condition
  Check for NULLs
  </steps>
- Two things set against each other: `compare`, each line left | right.
  <compare left="Inner join" right="Left join">
  Matched rows only | Every left row
  </compare>

Each tag on its own line as plain text, never in a code block; Markdown inside, nothing nested; your words around it as usual. One not exactly like these shows as plain words.
