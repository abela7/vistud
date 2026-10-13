<?php

/*
| Where a student's own key can come from (the owner's ask, 2026-10-12; App\Engine\Providers). OpenRouter is one key
| for every model, at the address the owner set up. Every other provider is asked directly, at its OpenAI-format
| address, with what makes it different:
|
| - prefix: how its keys start (one or several; the longest that fits wins). A key that fits none of a provider's
|   prefixes is still tried on the providers whose keys have no look of their own. prefix_unique: no one else's keys
|   start that way, so a fitting key isn't tried anywhere else. pattern: a key's shape, for keys without a prefix.
| - max_tokens_field: the name of the answer's length limit; stream_usage: ask for the usage at the end of a stream.
| - headers: sent with every call; key_header: the key sent again under this header; models_url: where the models
|   are listed, when not at {url}/models; skip: models listed that don't chat (a pattern).
| - tool loops (checked 2026-10-12): thought_signatures: Gemini's signature goes back with each call; reasoning_back:
|   the field the model's thinking goes back under (reasoning_required: a turn without it is refused, so a stand-in is
|   sent); tool_call_ids: "nine" for Mistral's nine-character ids; empty_content: what a call with no words says
|   instead of null; tools: false where the chat has no tools; tool_stream: ask for tool calls in a stream (Z.ai).
| - roles: the models for the tutor (its strongest) and for quick jobs (the reader and the helper), best first; the
|   first one the key can use is chosen.
| - models: what is known of a model: its name, its prices in dollars per million tokens (an answer's cost is worked
|   out from them, as a provider's own API doesn't say it), its context, whether it calls tools and sees pictures.
|
| - keys_url: where its account is topped up and its keys are made (a student sent here to pay for the API).
| - recommended (below): the models the AI settings page suggests, with the one line of why. Only models that call tools
|   are suggested, as the tutor looks things up; each is priced from "models" above.
|
| Checked against each provider's own documentation on 2026-10-12. Providers rename and reprice their models often:
| change them here. A model not named here still works; it is offered with tools, without pictures, at no known price.
*/

return [
    'providers' => [
        'openrouter' => [
            'name' => 'OpenRouter',
            'keys_url' => 'https://openrouter.ai/settings/credits',
            'prefix' => 'sk-or-',
            'prefix_unique' => true,
        ],

        'openai' => [
            'name' => 'OpenAI',
            'keys_url' => 'https://platform.openai.com/settings/organization/billing/overview',
            'url' => 'https://api.openai.com/v1',
            'prefix' => ['sk-proj-', 'sk-svcacct-', 'sk-'],
            // Its reasoning models refuse the older name.
            'max_tokens_field' => 'max_completion_tokens',
            // Astra and Sol call tools only in its Responses API, not this one: here they teach without look-ups.
            'roles' => ['tutor' => ['gpt-6-astra', 'gpt-6.1-sol'], 'quick' => ['gpt-6-luna']],
            'models' => [
                'gpt-6-astra' => ['name' => 'GPT-6 Astra', 'in' => 10.0, 'out' => 50.0, 'tools' => false, 'images' => true],
                'gpt-6.1-sol' => ['name' => 'GPT-6.1 Sol', 'in' => 2.0, 'out' => 10.0, 'tools' => false, 'images' => true],
                'gpt-6-luna' => ['name' => 'GPT-6 Luna', 'in' => 0.10, 'out' => 0.50, 'tools' => true, 'images' => true],
            ],
        ],

        'anthropic' => [
            'name' => 'Anthropic',
            'keys_url' => 'https://console.anthropic.com/settings/billing',
            'url' => 'https://api.anthropic.com/v1',
            'prefix' => 'sk-ant-',
            'prefix_unique' => true,
            // Its own API takes the key as x-api-key, with a version; the OpenAI-format one takes it as a bearer token.
            'key_header' => 'x-api-key',
            'headers' => ['anthropic-version' => '2023-06-01'],
            'roles' => ['tutor' => ['claude-opus-5-5', 'claude-sonnet-5-5'], 'quick' => ['claude-haiku-5-5']],
            'models' => [
                'claude-opus-5-5' => ['name' => 'Claude Opus 5.5', 'in' => 4.0, 'out' => 20.0, 'tools' => true, 'images' => true],
                'claude-sonnet-5-5' => ['name' => 'Claude Sonnet 5.5', 'in' => 2.0, 'out' => 10.0, 'tools' => true, 'images' => true],
                'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'in' => 0.10, 'out' => 0.50, 'tools' => true, 'images' => true],
            ],
        ],

        'google' => [
            'name' => 'Google Gemini',
            'keys_url' => 'https://aistudio.google.com/apikey',
            'url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            // AIza… keys before 2026-05-28, AQ.… keys since.
            'prefix' => ['AIza', 'AQ.'],
            'prefix_unique' => true,
            // Gemini 3 signs each tool call it makes and refuses the result without the signature back.
            'thought_signatures' => true,
            // Gemini 2.5 is closed to new keys (Google's own refusal, 2026-10-12); a free plan runs no Gemini Pro at all,
            // so the tutor steps down to Flash when Pro doesn't answer (App\Engine\Settings::answering).
            'roles' => ['tutor' => ['gemini-3.1-pro-preview', 'gemini-3.8-flash'], 'quick' => ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-2.5-flash-lite']],
            'models' => [
                'gemini-3.1-pro-preview' => ['name' => 'Gemini 3.1 Pro', 'in' => 2.0, 'out' => 12.0, 'tools' => true, 'images' => true],
                'gemini-3.8-flash' => ['name' => 'Gemini 3.8 Flash', 'in' => 0.75, 'out' => 3.75, 'tools' => true, 'images' => true],
                'gemini-3.5-flash-lite' => ['name' => 'Gemini 3.5 Flash-Lite', 'tools' => true, 'images' => true],
                'gemini-2.5-flash-lite' => ['name' => 'Gemini 2.5 Flash-Lite', 'in' => 0.10, 'out' => 0.40, 'tools' => true, 'images' => true],
            ],
        ],

        'xai' => [
            'name' => 'xAI (Grok)',
            'keys_url' => 'https://console.x.ai',
            'url' => 'https://api.x.ai/v1',
            'prefix' => 'xai-',
            'prefix_unique' => true,
            'roles' => ['tutor' => ['grok-4.7'], 'quick' => ['grok-4.20-0309-non-reasoning', 'grok-4.3']],
            'models' => [
                'grok-4.7' => ['name' => 'Grok 4.7', 'in' => 2.0, 'out' => 6.0, 'tools' => true, 'images' => true],
                'grok-4.3' => ['name' => 'Grok 4.3', 'in' => 1.25, 'out' => 2.50, 'tools' => true, 'images' => true],
                'grok-4.20-0309-non-reasoning' => ['name' => 'Grok 4.20 (quick)', 'in' => 1.25, 'out' => 2.50, 'tools' => true, 'images' => true],
            ],
        ],

        'groq' => [
            'name' => 'Groq',
            'keys_url' => 'https://console.groq.com/keys',
            'url' => 'https://api.groq.com/openai/v1',
            'prefix' => 'gsk_',
            'prefix_unique' => true,
            'roles' => ['tutor' => ['openai/gpt-oss-120b'], 'quick' => ['openai/gpt-oss-20b']],
            'models' => [
                'openai/gpt-oss-120b' => ['name' => 'GPT-OSS 120B', 'in' => 0.15, 'out' => 0.60, 'tools' => true, 'images' => false],
                'openai/gpt-oss-20b' => ['name' => 'GPT-OSS 20B', 'in' => 0.075, 'out' => 0.30, 'tools' => true, 'images' => false],
            ],
        ],

        'perplexity' => [
            'name' => 'Perplexity',
            'keys_url' => 'https://www.perplexity.ai/settings/api',
            // Its Sonar chat API ended 2026-09-27: the router speaks the OpenAI format now.
            'url' => 'https://api.perplexity.ai/router/v1',
            'prefix' => 'pplx-',
            'prefix_unique' => true,
            // Its chat has no tools (custom tools are in its Agent API only): it answers without look-ups.
            'tools' => false,
            'roles' => ['tutor' => ['perplexity/kimi-k3'], 'quick' => ['perplexity/glm-5.3-flash']],
            'models' => [
                'perplexity/kimi-k3' => ['name' => 'Kimi K3', 'in' => 3.0, 'out' => 15.0, 'tools' => false, 'images' => true],
                'perplexity/glm-5.3-flash' => ['name' => 'GLM 5.3 Flash', 'in' => 0.15, 'out' => 0.50, 'tools' => false, 'images' => false],
            ],
        ],

        'fireworks' => [
            'name' => 'Fireworks AI',
            'keys_url' => 'https://fireworks.ai/account/billing',
            'url' => 'https://api.fireworks.ai/inference/v1',
            'prefix' => 'fw_',
            'prefix_unique' => true,
            // Kimi and GLM think between tool calls and want that thinking back.
            'reasoning_back' => 'reasoning_content',
            'roles' => ['tutor' => ['accounts/fireworks/models/kimi-k3'], 'quick' => ['accounts/fireworks/models/glm-5p3']],
            'models' => [
                'accounts/fireworks/models/kimi-k3' => ['name' => 'Kimi K3', 'in' => 4.50, 'out' => 22.50, 'tools' => true, 'images' => false],
                'accounts/fireworks/models/glm-5p3' => ['name' => 'GLM 5.3', 'in' => 2.10, 'out' => 6.60, 'tools' => true, 'images' => false],
            ],
        ],

        'cerebras' => [
            'name' => 'Cerebras',
            'keys_url' => 'https://cloud.cerebras.ai',
            'url' => 'https://api.cerebras.ai/v1',
            'prefix' => 'csk-',
            'prefix_unique' => true,
            'roles' => ['tutor' => ['gpt-oss-120b'], 'quick' => ['qwen-3.8-27b']],
            'models' => [
                'gpt-oss-120b' => ['name' => 'GPT-OSS 120B', 'tools' => true, 'images' => false],
                'qwen-3.8-27b' => ['name' => 'Qwen 3.8 27B', 'tools' => true, 'images' => false],
            ],
        ],

        'together' => [
            'name' => 'Together AI',
            'keys_url' => 'https://api.together.ai/settings/billing',
            'url' => 'https://api.together.ai/v1',
            // Newer keys say so; older ones are 64 hex characters.
            'prefix' => 'tgp_v1_',
            'pattern' => '/^[0-9a-f]{64}$/',
            'reasoning_back' => 'reasoning_content',
            'roles' => ['tutor' => ['moonshotai/Kimi-K3'], 'quick' => ['MiniMaxAI/MiniMax-M3']],
            'models' => [
                'moonshotai/Kimi-K3' => ['name' => 'Kimi K3', 'in' => 2.70, 'out' => 13.50, 'tools' => true, 'images' => true],
                'MiniMaxAI/MiniMax-M3' => ['name' => 'MiniMax M3', 'in' => 0.30, 'out' => 1.20, 'tools' => true, 'images' => false],
            ],
        ],

        'zai' => [
            'name' => 'Z.ai (GLM)',
            'keys_url' => 'https://z.ai/manage-apikey/apikey-list',
            'url' => 'https://api.z.ai/api/paas/v4',
            // Its keys are 32 hex characters, a dot, and a secret.
            'pattern' => '/^[0-9a-f]{32}\.[A-Za-z0-9]+$/',
            // GLM wants its thinking back with the tool results, and a streamed tool call only when asked.
            'reasoning_back' => 'reasoning_content',
            'tool_stream' => true,
            'roles' => ['tutor' => ['glm-5.3'], 'quick' => ['glm-5.3-flash']],
            'models' => [
                'glm-5.3' => ['name' => 'GLM 5.3', 'in' => 1.40, 'out' => 4.40, 'tools' => true, 'images' => false],
                'glm-5.3-flash' => ['name' => 'GLM 5.3 Flash', 'in' => 0.15, 'out' => 0.50, 'tools' => true, 'images' => false],
            ],
        ],

        // Plain "sk-" keys: tried in this order (OpenAI's newer keys say so by their prefix, above).
        'deepseek' => [
            'name' => 'DeepSeek',
            'keys_url' => 'https://platform.deepseek.com/top_up',
            'url' => 'https://api.deepseek.com/v1',
            'prefix' => 'sk-',
            // With tools on, every assistant turn must carry its reasoning_content, or it refuses the call.
            'reasoning_back' => 'reasoning_content',
            'reasoning_required' => true,
            'roles' => ['tutor' => ['deepseek-v4-pro'], 'quick' => ['deepseek-flash']],
            'models' => [
                'deepseek-v4-pro' => ['name' => 'DeepSeek V4 Pro', 'in' => 1.32, 'out' => 3.96, 'tools' => true, 'images' => false],
                'deepseek-flash' => ['name' => 'DeepSeek Flash', 'in' => 0.30, 'out' => 1.20, 'tools' => true, 'images' => true],
            ],
        ],

        'moonshot' => [
            'name' => 'Moonshot (Kimi)',
            'keys_url' => 'https://platform.moonshot.ai/console/api-keys',
            'url' => 'https://api.moonshot.ai/v1',
            'prefix' => 'sk-',
            // Kimi's thinking stays on its tool-call turns through a tool loop.
            'reasoning_back' => 'reasoning_content',
            'reasoning_required' => true,
            'roles' => ['tutor' => ['kimi-k3'], 'quick' => ['kimi-k2.6']],
            'models' => [
                'kimi-k3' => ['name' => 'Kimi K3', 'in' => 3.0, 'out' => 15.0, 'tools' => true, 'images' => true],
                'kimi-k2.6' => ['name' => 'Kimi K2.6', 'in' => 0.95, 'out' => 4.0, 'tools' => true, 'images' => true],
            ],
        ],

        // A key works only in its own region: the international one first, then the US.
        'alibaba' => [
            'name' => 'Alibaba Qwen',
            'keys_url' => 'https://modelstudio.console.alibabacloud.com/',
            'url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
            'prefix' => 'sk-',
            // A tool call's words are "", never null.
            'empty_content' => '',
            'roles' => ['tutor' => ['qwen3.8-max', 'qwen3.7-plus'], 'quick' => ['qwen3.8-flash']],
            'models' => [
                'qwen3.8-max' => ['name' => 'Qwen 3.8 Max', 'in' => 2.0, 'out' => 6.0, 'tools' => true, 'images' => false],
                'qwen3.7-plus' => ['name' => 'Qwen 3.7 Plus', 'in' => 0.40, 'out' => 1.60, 'tools' => true, 'images' => false],
                'qwen3.8-flash' => ['name' => 'Qwen 3.8 Flash', 'in' => 0.15, 'out' => 0.47, 'tools' => true, 'images' => false],
            ],
        ],

        'alibaba_us' => [
            'name' => 'Alibaba Qwen (US)',
            'keys_url' => 'https://modelstudio.console.alibabacloud.com/',
            'url' => 'https://dashscope-us.aliyuncs.com/compatible-mode/v1',
            'prefix' => 'sk-',
            // A tool call's words are "", never null.
            'empty_content' => '',
            'roles' => ['tutor' => ['qwen3.8-max', 'qwen3.7-plus'], 'quick' => ['qwen3.8-flash']],
            'models' => [
                'qwen3.8-max' => ['name' => 'Qwen 3.8 Max', 'in' => 2.0, 'out' => 6.0, 'tools' => true, 'images' => false],
                'qwen3.7-plus' => ['name' => 'Qwen 3.7 Plus', 'in' => 0.40, 'out' => 1.60, 'tools' => true, 'images' => false],
                'qwen3.8-flash' => ['name' => 'Qwen 3.8 Flash', 'in' => 0.15, 'out' => 0.47, 'tools' => true, 'images' => false],
            ],
        ],

        // Keys with no look of their own: tried when no prefix fits.
        'mistral' => [
            'name' => 'Mistral',
            'keys_url' => 'https://console.mistral.ai/billing',
            'url' => 'https://api.mistral.ai/v1',
            // It refuses a tool call id that isn't nine letters and digits (another model's, kept in the chat).
            'tool_call_ids' => 'nine',
            'roles' => ['tutor' => ['mistral-large-latest', 'mistral-medium-latest'], 'quick' => ['mistral-small-latest']],
            'models' => [
                'mistral-large-latest' => ['name' => 'Mistral Large', 'in' => 0.68, 'out' => 2.09, 'tools' => true, 'images' => true],
                'mistral-medium-latest' => ['name' => 'Mistral Medium', 'in' => 1.50, 'out' => 7.50, 'tools' => true, 'images' => true],
                'mistral-small-latest' => ['name' => 'Mistral Small', 'in' => 0.15, 'out' => 0.60, 'tools' => true, 'images' => false],
            ],
        ],

        'cohere' => [
            'name' => 'Cohere',
            'keys_url' => 'https://dashboard.cohere.com/billing',
            'url' => 'https://api.cohere.ai/compatibility/v1',
            // Its OpenAI-format API doesn't list models: its own API does ({models: [{name}]}).
            'models_url' => 'https://api.cohere.com/v1/models?endpoint=chat',
            'roles' => ['tutor' => ['command-a-plus-05-2026'], 'quick' => ['command-r7b-12-2024']],
            'models' => [
                'command-a-plus-05-2026' => ['name' => 'Command A Plus', 'tools' => true, 'images' => true],
                'command-r7b-12-2024' => ['name' => 'Command R7B', 'tools' => true, 'images' => false],
            ],
        ],
    ],

    // A study session is about this many tokens in (the conversation is sent again with each message) and out: what
    // a model's "about $X a session" is worked out from.
    'session_tokens' => ['in' => 150_000, 'out' => 10_000],

    // Best first within each kind: smartest (the strongest tutors), value (clever and cheap) and cheapest (good enough).
    'recommended' => [
        ['kind' => 'smartest', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'why' => 'The best explainer. Costs the most.'],
        ['kind' => 'smartest', 'provider' => 'google', 'model' => 'gemini-3.1-pro-preview', 'why' => 'Very strong. Needs billing on at Google.'],
        ['kind' => 'value', 'provider' => 'deepseek', 'model' => 'deepseek-v4-pro', 'why' => 'Near the top, for far less. Best for most.'],
        ['kind' => 'value', 'provider' => 'google', 'model' => 'gemini-3.8-flash', 'why' => 'Fast and clever. Works on the free plan.'],
        ['kind' => 'value', 'provider' => 'mistral', 'model' => 'mistral-large-latest', 'why' => 'A solid all-rounder at a low price.'],
        ['kind' => 'cheapest', 'provider' => 'openai', 'model' => 'gpt-6-luna', 'why' => 'Pennies a session. Good for revising.'],
        ['kind' => 'cheapest', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'why' => 'Very cheap and fast.'],
    ],
];
