<?php
/**
 * Contribution Meter Block loader.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Contribution_Meter;

use Newspack\Contribution_Meter\Contribution_Meter;
use Newspack\Contribution_Meter\Block_Patterns;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-contribution-meter-block.php';

Contribution_Meter::init();
Block_Patterns::init();
Contribution_Meter_Block::init();
