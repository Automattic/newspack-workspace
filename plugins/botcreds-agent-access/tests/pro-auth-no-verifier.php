<?php
/**
 * php -d disable_functions=sodium_crypto_sign_verify_detached \
 * tests/pro-auth-no-verifier.php /absolute/plugin/includes/class-agent-access-pro-auth.php
 * Isolated PHP process: exercises the genuinely unavailable verifier branch.
 */
define('ABSPATH','test-only');
if (empty($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Supply the production Pro Auth class path');
require $argv[1];
if(function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('Verifier must be disabled for this test');
$method=new ReflectionMethod('Agent_Access_Pro_Auth','verify_signature');
if(false!==$method->invoke(null,'synthetic-public-key')) throw new RuntimeException('Unavailable verifier must fail closed');
echo "PASS 2 unavailable-verifier assertions (disabled function and rejected request)\n";
