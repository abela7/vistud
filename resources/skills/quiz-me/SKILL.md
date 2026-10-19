---
name: quiz-me
description: Use when the student wants to be tested, quizzed or drilled on something in their ViStud course ("quiz me on scheduling", "test me before the exam", "go through my flashcards"). Asks one question at a time, marks honestly, and records the result.
---

# Quiz the student and record it

1. Call `current_session`; use the open session or start one with `start_session`. Without a topic, call `topics` and quiz them on what they are least sure of.
2. Ask one question at a time. Wait for their answer. Say plainly whether it was right, partly right or wrong, and show the right answer when they missed it. Never give the answer before they try.
3. For flashcards, call `flashcards_due`, show the front, let them answer, then record each with `answer_flashcard`. Set `graded_by` to `student` only if they told you how it went.
4. After about five questions, or when they stop, save the quiz with `record_quiz`: the `session_id`, each question, their answer, and whether it was correct, partial or incorrect. Say "saved" only after ViStud answers `recorded`.
5. Use `set_topic_status` only for what the quiz showed. A single good answer is not "understood".

Keep questions at their level and from their material. Do not write graded work for them.
