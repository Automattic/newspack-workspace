import { execFileSync } from "child_process";
import { readFileSync } from "fs";
import { resolve } from "path";

// Provision the target site into a known state for a test phase, by running
// e2e-setup.sh (which wraps newspack-manager's site-setup.sh) against it.
//
// The site is driven over the shell rather than the browser: locally via
// `docker exec` into the env's container, on CI via SSH to the remote host. The
// script is streamed over stdin so nothing has to be copied onto the site first.

type SetupOptions = {
  // Whether to provision the WooCommerce stack (donations, memberships, subscriptions).
  woo: boolean;
};

const SCRIPT_PATH = resolve(__dirname, "..", "e2e-setup.sh");
// site-setup.sh ships in this repo (dev-only tooling); we copy it onto the target
// and point e2e-setup.sh at it via SITE_SETUP_SCRIPT.
const SITE_SETUP_PATH = resolve(__dirname, "..", "site-setup.sh");
const REMOTE_SITE_SETUP = "/tmp/e2e-site-setup.sh";

// A site is "local" when it lives in a Docker env we can `docker exec` into:
// *.local / *.test hosts and loopback addresses. Everything else is remote (SSH).
const isLocalTarget = (siteUrl: string): boolean => {
  try {
    const { hostname } = new URL(siteUrl);
    return (
      hostname.endsWith(".local") ||
      hostname.endsWith(".test") ||
      /^127\.|^localhost$/.test(hostname)
    );
  } catch {
    return false;
  }
};

// Derive the env's container name from its host, e.g. `e2e-release.test` ->
// `newspack_env_e2e_release`. Override with E2E_CONTAINER when it doesn't match.
const containerForHost = (siteUrl: string): string => {
  if (process.env.E2E_CONTAINER) {
    return process.env.E2E_CONTAINER;
  }
  const { hostname } = new URL(siteUrl);
  const name = hostname.replace(/\.(local|test)$/, "").replace(/-/g, "_");
  return `newspack_env_${name}`;
};

// POSIX-quote a string so it survives interpolation into a remote shell command.
// (JSON.stringify is NOT a shell quoter: inside double quotes the remote shell still
// expands $, backticks and backslashes, which would corrupt e.g. a password with a $.)
const shQuote = (s: string): string => `'${s.replace(/'/g, `'\\''`)}'`;

// Build the argument list passed through to e2e-setup.sh.
const scriptArgs = (woo: boolean): string[] => [
  woo ? "--woo" : "--no-woo",
  "--url",
  process.env.SITE_URL as string,
  "--admin-user",
  process.env.ADMIN_USER as string,
  "--admin-password",
  process.env.ADMIN_PASSWORD as string,
];

export const setupSite = ({ woo }: SetupOptions): void => {
  for (const key of ["SITE_URL", "ADMIN_USER", "ADMIN_PASSWORD"]) {
    if (!process.env[key]) {
      throw new Error(`${key} must be set to provision the site.`);
    }
  }
  const siteUrl = process.env.SITE_URL as string;

  const script = readFileSync(SCRIPT_PATH);
  const siteSetup = readFileSync(SITE_SETUP_PATH);
  const args = scriptArgs(woo);
  // Forward Stripe test keys (if present) into the target environment.
  const stripeEnv = ["STRIPE_PUB_KEY", "STRIPE_SECRET_KEY"];

  if (isLocalTarget(siteUrl)) {
    const container = containerForHost(siteUrl);
    // Copy site-setup.sh into the container, then run e2e-setup.sh (piped) pointing
    // at it. Local Docker runs as root, so the script needs --allow-root and can do
    // a full DROP/CREATE-DATABASE reset.
    execFileSync("docker", ["cp", SITE_SETUP_PATH, `${container}:${REMOTE_SITE_SETUP}`], {
      stdio: ["ignore", "inherit", "inherit"],
    });
    const envForwards = stripeEnv.flatMap((v) => (process.env[v] ? ["-e", v] : []));
    execFileSync(
      "docker",
      [
        "exec", "-i",
        "-e", `SITE_SETUP_SCRIPT=${REMOTE_SITE_SETUP}`,
        ...envForwards,
        container, "bash", "-s", "--", ...args, "--allow-root", "--reset", "full",
      ],
      { input: script, stdio: ["pipe", "inherit", "inherit"] }
    );
    return;
  }

  // Remote (CI / Atomic): SSH in and pipe the scripts. No --allow-root on a managed
  // host, and a `clean` reset (drop tables, keep the DB) since we can't DROP DATABASE.
  const host = process.env.E2E_SSH_HOST;
  const user = process.env.E2E_SSH_USER;
  const pass = process.env.E2E_SSH_USER_PASS;
  const wpPath = process.env.E2E_REMOTE_WP_PATH ?? "htdocs";
  if (!host || !user) {
    throw new Error(
      "Remote provisioning needs E2E_SSH_HOST and E2E_SSH_USER (and E2E_SSH_USER_PASS for password auth)."
    );
  }

  // Run one SSH command, piping `input` to it. Uses sshpass for password auth.
  const runSsh = (remoteCommand: string, input: Buffer): void => {
    const sshArgs = ["-o", "StrictHostKeyChecking=no", `${user}@${host}`, remoteCommand];
    const [cmd, cmdArgs] = pass ? ["sshpass", ["-p", pass, "ssh", ...sshArgs]] : ["ssh", sshArgs];
    try {
      execFileSync(cmd, cmdArgs, { input, stdio: ["pipe", "inherit", "inherit"] });
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code === "ENOENT" && cmd === "sshpass") {
        throw new Error(
          "sshpass is required for E2E_SSH_USER_PASS auth but was not found on PATH. Install sshpass, or use key-based SSH (unset E2E_SSH_USER_PASS)."
        );
      }
      throw err;
    }
  };

  // 1. Ship site-setup.sh onto the target.
  runSsh(`cat > ${shQuote(REMOTE_SITE_SETUP)}`, siteSetup);

  // 2. Run e2e-setup.sh (piped), pointing it at the copy and forwarding Stripe keys.
  const inlineEnv = [
    `SITE_SETUP_SCRIPT=${shQuote(REMOTE_SITE_SETUP)}`,
    ...stripeEnv
      .filter((v) => process.env[v])
      .map((v) => `${v}=${shQuote(process.env[v] as string)}`),
  ].join(" ");
  const remoteCmd = `cd ${shQuote(wpPath)} && ${inlineEnv} bash -s -- ${args
    .map(shQuote)
    .join(" ")} --reset clean`;
  runSsh(remoteCmd, script);
};
