<?php

namespace Tests\Unit;

use App\Ai\Agents\GlobalScoutAgent;
use App\Ai\Tools\AllowedDomainHttpFetchTool;
use Laravel\Ai\Providers\Tools\WebSearch;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GlobalScoutAgentToolsTest extends TestCase
{
    #[Test]
    public function scout_tools_use_allowed_domain_fetch_not_unmapped_web_fetch(): void
    {
        $tools = iterator_to_array(GlobalScoutAgent::make()->tools());

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(AllowedDomainHttpFetchTool::class, $tools[0]);
        $this->assertInstanceOf(WebSearch::class, $tools[1]);
    }
}
