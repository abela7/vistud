{{-- A chat-app panel (M6.6). No student text is written into an attribute. Answers go through callServerTool. A confirmation card only opens the ViStud page. --}}
<x-mcp::app :title="$title">
    <x-slot:head>
        <style>
            body {
                font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
                background: var(--color-background-primary);
                color: var(--color-text-primary);
                margin: 0;
                padding: 16px;
            }
            p { margin: 0 0 12px; line-height: 1.4; }
            button {
                min-height: 44px;
                min-width: 44px;
                margin: 0 8px 8px 0;
                padding: 8px 14px;
                font: inherit;
                border-radius: 8px;
                border: 1px solid var(--color-border-primary);
                background: var(--color-background-secondary);
                color: inherit;
            }
            button.primary {
                background: var(--color-text-primary);
                color: var(--color-background-primary);
                border-color: transparent;
            }
            label { display: block; min-height: 44px; line-height: 44px; }
        </style>
        <script type="module">
            const kind = @json($kind);
            const newer = (a, b) => {
                const [as, af = '0'] = String(a).split('.');
                const [bs, bf = '0'] = String(b).split('.');
                if (as.length !== bs.length) return as.length > bs.length;
                if (as !== bs) return as > bs;
                return af > bf;
            };
            createMcpApp(async (app) => {
                let version = '';
                const root = document.getElementById('card');
                const textOf = (result) => result?.content?.[0]?.text ?? '';
                const dataOf = (result) => result?.structuredContent ?? {};
                const fresh = (data) => {
                    if (!data.ui_version || (version !== '' && !newer(data.ui_version, version))) return false;
                    version = data.ui_version;
                    return true;
                };
                const say = (text) => { root.replaceChildren(); const p = document.createElement('p'); p.textContent = text; root.append(p); };
                const button = (label, primary, on) => {
                    const el = document.createElement('button');
                    el.type = 'button';
                    el.textContent = label;
                    if (primary) el.className = 'primary';
                    el.addEventListener('click', on);
                    return el;
                };

                if (kind === 'quiz') {
                    say('The quiz appears here.');
                    app.onToolResult((params) => {
                        const data = dataOf(params.result);
                        if (!fresh(data) || !Array.isArray(data.questions)) return;
                        root.replaceChildren();
                        const picked = [];
                        data.questions.forEach((question, i) => {
                            const p = document.createElement('p');
                            p.textContent = (i + 1) + '. ' + (question.asked ?? '');
                            root.append(p);
                            (question.choices ?? []).forEach((choice) => {
                                const label = document.createElement('label');
                                const input = document.createElement('input');
                                input.type = 'radio';
                                input.name = 'q' + i;
                                input.addEventListener('change', () => { picked[i] = choice; });
                                label.append(input, document.createTextNode(' ' + choice));
                                root.append(label);
                            });
                        });
                        root.append(button('Save answers', true, async () => {
                            const questions = data.questions.map((question, i) => {
                                const answer = picked[i] ?? '';
                                const row = { asked: question.asked, answer: answer, topic_id: data.topic_id };
                                if (question.right) row.result = answer === question.right ? 'correct' : 'incorrect';
                                return row;
                            });
                            if (questions.some((row) => !row.result)) {
                                await app.sendMessage('Here are my answers. Please save them with record_quiz: ' + JSON.stringify(questions));
                                say('Sent to the chat to save.');
                                return;
                            }
                            const result = await app.callServerTool({ name: 'record_quiz', arguments: { course_id: data.course_id, session_id: data.session_id, kind: 'quiz', topic_id: data.topic_id, questions } });
                            say(result.isError ? (textOf(result) || 'That did not save.') : 'Saved.');
                        }));
                    });
                }

                if (kind === 'topic' || kind === 'session') {
                    say(kind === 'topic' ? 'Where the topic stands appears here.' : 'The session summary appears here.');
                    app.onToolResult((params) => {
                        const data = dataOf(params.result);
                        if (!fresh(data)) return;
                        say(textOf(params.result) || 'Done.');
                    });
                }

                if (kind === 'confirm') {
                    say('A change waiting for you appears here. Nothing changes until you press Yes on the ViStud page.');
                    let url = '';
                    app.onToolResult((params) => {
                        const data = dataOf(params.result);
                        if (!fresh(data) || typeof data.confirm_url !== 'string') return;
                        url = data.confirm_url;
                        root.replaceChildren();
                        const p = document.createElement('p');
                        p.textContent = textOf(params.result);
                        root.append(p, button('Open the ViStud page', true, () => { if (url !== '') app.openLink(url); }));
                    });
                }

                if (kind === 'cards') {
                    say('A flashcard appears here.');
                    app.onToolResult((params) => {
                        const data = dataOf(params.result);
                        if (!fresh(data) || !Array.isArray(data.cards) || data.cards.length === 0) return;
                        const show = (i) => {
                            const card = data.cards[i];
                            if (!card) { say('No more cards.'); return; }
                            root.replaceChildren();
                            const front = document.createElement('p');
                            front.textContent = card.front ?? '';
                            root.append(front, button('Show the answer', true, () => {
                                const back = document.createElement('p');
                                back.textContent = card.back ?? '';
                                root.append(back, button('Knew it', true, async () => {
                                    await app.callServerTool({ name: 'answer_flashcard', arguments: { course_id: data.course_id, card_id: card.id, result: 'correct', graded_by: 'student' } });
                                    show(i + 1);
                                }), button('Missed it', false, async () => {
                                    await app.callServerTool({ name: 'answer_flashcard', arguments: { course_id: data.course_id, card_id: card.id, result: 'incorrect', graded_by: 'student' } });
                                    show(i + 1);
                                }));
                            }));
                        };
                        show(0);
                    });
                }
            });
        </script>
    </x-slot:head>
    <div id="card">
        @if ($kind === 'quiz')
            <p>The quiz appears here.</p>
            <button type="button" class="primary">Save answers</button>
        @elseif ($kind === 'topic')
            <p>Where the topic stands appears here.</p>
        @elseif ($kind === 'session')
            <p>The session summary appears here.</p>
        @elseif ($kind === 'confirm')
            <p>A change waiting for you appears here. Nothing changes until you press Yes on the ViStud page.</p>
            <button type="button" class="primary">Open the ViStud page</button>
        @else
            <p>A flashcard appears here.</p>
            <button type="button" class="primary">Show the answer</button>
        @endif
    </div>
</x-mcp::app>
