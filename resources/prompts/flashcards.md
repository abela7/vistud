<!--
The card-making prompt: what ViStud gives any AI to make flashcards from
the student's material (docs/specs/study-memory.md §4.5). Edit it freely.
The words in double braces are filled by App\Study\CardMaker, and the
material follows the prompt. The student pastes the AI's reply back, and
ViStud reads the flashcard marks in it, as it does after a study session.
This comment is for people: it's left out of what the AI receives.

Built from:
- Piotr Wozniak, "Effective learning: Twenty rules of formulating
  knowledge" (SuperMemo, 1999): understand first, the minimum information
  principle, no sets or lists, one answer per question, wording that
  can't be misread.
- Andy Matuschak, "How to write good prompts: using spaced repetition to
  create understanding" (2020): focused, precise, consistent answers,
  effortful retrieval, questions about why and how as well as what.
-->
You are helping a student of {{course}} make flashcards. They will review
the cards in ViStud, a few at a time, days and weeks apart, and answer each
one from memory before turning it over.

## What to make

{{count}} flashcards about {{topic}}, from the material below.

## What makes a good card

- One idea per card. Split a list or a process into several cards, one step
  or item each, rather than asking for the whole list at once.
- The front asks one clear question that has one answer. Someone who knows
  the material should give the same answer every time.
- The back is short: the answer in a sentence or two, no more. Put the
  reason in if the card is about why.
- Ask for understanding as well as facts: why something happens, how two
  ideas differ, what happens if something changes, when to use what.
- Use the course's own words, as the material uses them.
- Stick to the material. If it's too thin for {{count}} good cards, make
  fewer, and say so in a sentence at the end.
- Don't repeat a card the student already has (they're listed below, if
  there are any).

## How to write them

Write each card exactly like this, one after another:

<flashcard topic="{{topic_mark}}"><front>The question</front><back>The answer</back></flashcard>

For example, in a biology course:

<flashcard topic="Photosynthesis"><front>Where in the plant cell does photosynthesis happen?</front><back>In the chloroplasts.</back></flashcard>

<flashcard topic="Photosynthesis"><front>Why do plants need light for photosynthesis?</front><back>Light gives the energy to split water and make the ATP and NADPH that build sugar.</back></flashcard>

{{topic_rule}}

Write the cards in plain text, not in a table, and add nothing else
between them. The student pastes your whole reply into ViStud, which reads
the cards and ignores everything else.
