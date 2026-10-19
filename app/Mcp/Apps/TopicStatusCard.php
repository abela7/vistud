<?php

namespace App\Mcp\Apps;

use Laravel\Mcp\Server\Attributes\Description;

#[Description('Shows where a topic stands after set_topic_status. It changes nothing.')]
final class TopicStatusCard extends ViStudApp
{
    protected string $title = 'Topic';

    protected function kind(): string
    {
        return 'topic';
    }
}
