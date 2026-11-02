<?php

namespace App\Ai\Tools\Contracts;

/**
 * Marks a tool that only reads the authenticated student's data. Only these tools (plus provider
 * search) may be offered in Ask mode; MentorToolPolicy refuses anything else.
 */
interface ReadOnlyTool {}
