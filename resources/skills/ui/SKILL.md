---
name: ui
description: Use when a reply reads better as a card, a snippet to copy, choices to tap, a checklist or a side-by-side comparison. Built into ViStud's own chats; outside apps don't draw these.
where: vistud
---

## Showing things in the chat

ViStud draws these five blocks. Write a tag on its own line as plain text (never in a code block), Markdown inside, nothing nested; one not exactly like these shows as plain words. Use one when it helps the student see or act; most replies are plain words.

- A card for one thing to keep (a definition, a rule):
  <card title="Left join">Keeps every row of the left table, matched or not.</card>
- Text to copy exactly as it is (a command, a citation):
  <copy>SELECT * FROM a LEFT JOIN b ON a.id = b.a_id;</copy>
- Choices answered with one tap, 2 to 6, one a line (a tap sends that line as their message):
  <choices>
  A left join
  An inner join
  </choices>
- A checklist they tick, 2 to 12 steps, one a line:
  <steps>
  Name the tables
  Write the join condition
  </steps>
- A comparison, each line left | right:
  <compare left="Inner join" right="Left join">
  Matched rows only | Every left row
  </compare>
