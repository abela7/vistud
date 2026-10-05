# ViStud 2 · Phase 9 (Study by folder): handoff

Branch `claude/persistent-study-context-zsilo6`. Asked for by the owner on 2026-10-05, with a screenshot of his Canvas week (*Week 1: OS Structure | Processes & Threads*: Lecture 1, Lecture 2, Lab 01, Lab 02): *"when I study I should study lecture 1 with lab one… create two folders under week one… then I start study the folder… instead of start study anywhere and confuse the AI… the AI first understands where he is, then focuses on the folder and the files; when I tell him to create a note it will focus based on the folder… topic should be only based on the folder. One folder, everything inside it including topic, note, questions, flashcards."* The plan is Phase 9 of [the blueprint](../specs/vistud-2-blueprint.md); the series so far is in [v2-phase-8.md](v2-phase-8.md).

## What was built

**The idea.** A folder in a module is a **place to study**. The week stays the module (its page still shows everything in it); each folder in it (*Lecture 1 + Lab 1*, *Lecture 2 + Lab 2*) is a sitting with its own topics, notes, questions and cards. Nothing is required: a module without folders works as before, and so does studying a whole module. "In a folder" always means the folder and the folders inside it.

**Data** (migration `2026_10_09_100000_add_folders_to_study`, additive): `folder_id` beside `module_id` on `study_sessions`, `topics`, `questions`, `flashcards`, `quizzes` and `topic_suggestions`, and a `folder_briefs` table for the folder's part of what the tutor is told. It is organisation only: the study record (journal) is unchanged.
- A session started on a folder keeps it (its mode *Whole folder*); studying a topic that is in a folder studies in that folder.
- A topic made in a folder belongs to it: added on its page, found by the reader in its files (*Add all*), or made by the tutor in its session. Topic names stay unique in the course.
- A card, question or quiz takes its topic's folder; with no topic, the folder of the session it was made in.
- Moving a folder to another module takes everything in it along; deleting an empty folder lifts its topics, cards and questions to where it was. A file put in a folder brings the topics the reader found in it that are still waiting to be added.

**The tutor in a folder** (`App\Engine\Context\Stack`, layer 4). Its context starts with **where you are**: *Operating Systems › Week 1: OS Structure | Processes & Threads (21 Sep – 27 Sep) › Lecture 1 + Lab 1*, then what that means (teach from this folder's files, keep to its topics, what you save goes in it; the module's other folders are for other sessions, by name). Then the folder's own brief: its files, its topics with the student's word on each, its open questions, key points and where the last session in it stopped. The module's other topics and files are left out. The tools default to the folder: `module_files` and `topics` list the folder's (the tutor can ask for the module or everything), `read_file` and `read_note` look in the folder first, and `set_topic`, `add_topics`, the cards, questions, key points and notes it saves, and the topics and note the session's end writes, go in the folder. The tutor's rules (`resources/prompts/tutor-2.md`) are unchanged.

**Screens**
- **A folder's page** (in a module): the module's name above the folder's, *1 of 2 understood*, **Study this ▾** (*Whole folder · Pick a topic · Quiz me · Test me*), and its own tabs **Topics · Files · Notes · Questions · Cards**: the topics the reader found in its files with *Add all*, its topics with Study, its files and notes, its questions (with a box to ask one), its cards with *Review* and *Open in Cards*. An empty folder says so and offers **Go to Files**. A folder outside every module stays a place to keep things, with no Study.
- **A module's Topics tab** shows the module's own topics first (*In the module*), then each folder as a heading with its numbers and its own **Study**, then its topics.
- **The session in a folder** is named for it, with *Week 1 › Lecture 1 + Lab 1* above; the side panel is *Topics in Lecture 1 + Lab 1* and the folder's material; Back goes to the folder. The chat offers the folder's notes and files, an upload goes into the folder, and *Quiz me* offers *On Lecture 1 + Lab 1*.
- **A topic's sheet** has **Module or folder**: a module, or one of its folders, so a topic made before the folders existed can be put in one.
- **Cards** from a folder show only its cards (*Only the cards in Lecture 1 + Lab 1*, with *Show the whole module*), and its review round is its cards. **Progress** shows a module's topics under their folders.
- Fixed while testing:
  - On a module's Topics tab the folder's name no longer covers the other folders' Study buttons.
  - A module's (or folder's) page now redraws when a topic's sheet changes a topic (its status, name or place); before, it showed only after a reload unless a file was being read.
  - A folder right in a module names its module once above its title; the path line is for folders deeper down.
  - The session's side panel keeps a long file name inside it (it ends in "…").
  - Two uploads at the same moment by different students could fail (a database deadlock); the upload is now tried again.

**Tests added or changed**: `Study/FolderStudyTest` (sessions, topics, cards, questions and quizzes finding their folder, lists by folder, the reader's topics, a file bringing them, moving and deleting folders, the folder's brief), `Engine/FolderSessionTest` (where you are, the folder's layer and what is left out, look-ups defaulting to the folder, topics, cards, questions, notes and the session's end going in it, the chat's material, uploads and quiz), `Web/FolderStudyScreenTest` (the folder's page and tabs, a loose folder, adding topics and questions, cards and their review, the module's Topics tab, Study now on a folder, the session, the topic sheet's folder choice, Progress, and Progress's move keeping a topic's folder), `ModulesScreenTest`, `NotesScreenTest` and `ModulePageScreenTest` brought up to date. Browser: `folders.spec.js` (the folder's page on a computer and a phone, Whole folder, the module's folders and moving a topic between them, questions and cards, tokens, axe in three themes, 320 px at 200 % text, previews) and the `folder:` test in `chat.spec.js` (the tutor's cards and note in a folder's session land on the folder's tabs). Previews: `folder-*`, `module-folders-*`, `folder-session-*`.

## What Abel tests (plain steps)

Use your OS course and its Week 1. **Never paste a key or a token into any chat.**

**Part 1: make the two folders**
1. Open **Modules → Week 1**, then the **Files** tab. Press **New folder**, name it *Lecture 1 + Lab 1*. Press **New folder** again: *Lecture 2 + Lab 2*.
2. Put the files in them. If Lecture 1 and Lab 01 are already in Week 1: on each file press **⋯ → Move to…** and choose *Lecture 1 + Lab 1* (or press **Select**, tick both, press **Move…**). Do the same with Lecture 2 and Lab 02. If they are not uploaded yet: open the folder, **Files**, **Upload files**.
3. Open *Lecture 1 + Lab 1*. At the top you see *Week 1…* above its name and **Study this**. Under it, five tabs: **Topics · Files · Notes · Questions · Cards**. Files shows only the lecture and the lab.

**Part 2: the folder's topics**
4. On the folder's **Topics** tab, once the AI has read the files (on **Files**, a file says *Read* when done; press **Read now** if it hasn't started), you see *new topics found in Lecture 1…* Press **Add all**: they are this folder's topics. Topics found earlier (before the folders) come along with the file if you hadn't added them yet.
5. Topics you added before making the folders sit under *In the module* on Week 1's Topics tab. Click a topic's name, and in its sheet choose **Module or folder → Lecture 1 + Lab 1**, then **Move**.
6. Go back to **Week 1 → Topics**. You see *In the module* (if any are left), then *Lecture 1 + Lab 1* with its topics and a **Study** button, then *Lecture 2 + Lab 2* with its own.

**Part 3: study the folder**
7. Open *Lecture 1 + Lab 1*, press **Study this → Whole folder**. The session's title is *Lecture 1 + Lab 1*, with *Week 1… › Lecture 1 + Lab 1* above it. The side panel says *Topics in Lecture 1 + Lab 1*.
8. Ask the tutor: *Where are we?* It should name the course, the week and the folder, and talk about the lecture and the lab, not Lecture 2.
9. Ask: *Make a note of what we covered* and *Make 3 flashcards on this*. Then press **End**.
10. Open the folder again: the note is on its **Notes** tab, the cards on its **Cards** tab (press **Review** to go through only these). *Lecture 2 + Lab 2* has none of them.
11. **Progress** shows Week 1's topics under the two folders.
12. On the phone, the folder's page and the session fit the screen and nothing slides sideways.

If the tutor talks about the wrong folder, or something you made lands in the wrong place, tell me what you did and where it went.

## What is left or deferred

- The tutor's behaviour in a folder with a real model is unproven here (the tests use a scripted model). Step 8 above is the check.
- A topic that was added before its file went into a folder stays where it was; the topic sheet moves it (step 5). Moving many topics at once into a folder is not built (Progress's *Move to module…* moves between modules and keeps a topic's folder when the module is the same).
- The course home's *Next* suggestion still names a module or a topic, not a folder.
- Notes the student writes by hand go where they are made, as before (the folder's Notes tab, **New note**).

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
3. composer migrate
   (this adds the new folder columns; it must say the migration 2026_10_09_100000_add_folders_to_study ran or was already run)
4. npm run build
5. php artisan test --compact tests/Feature/Study/FolderStudyTest.php tests/Feature/Engine/FolderSessionTest.php tests/Feature/Web/FolderStudyScreenTest.php tests/Feature/Web/ModulesScreenTest.php tests/Feature/Web/ModulePageScreenTest.php tests/Feature/Web/NotesScreenTest.php tests/Feature/Web/ProgressScreenTest.php tests/Architecture/StudentWordsTest.php
   Tell me the pass/fail counts and the text of any failure.

Do not run migrate:fresh or anything that deletes data. Do not push anything. Do not run anything else.
```
