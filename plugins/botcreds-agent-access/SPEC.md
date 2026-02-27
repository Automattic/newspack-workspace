# ClawPress — WordPress Plugin

## Purpose
A WordPress plugin that provides a wp-admin wizard for creating and revoking OpenClaw Application Passwords. Makes it dead simple for non-technical users to connect OpenClaw to their WordPress site.

## MVP Features
1. **wp-admin menu page** under Settings → ClawPress
2. **Create Connection wizard:**
   - One-click button to generate an Application Password named "OpenClaw"
   - Display the generated password ONCE with a copy button
   - Show the site URL + username + password in a ready-to-paste format (or copyable JSON)
   - Clear instructions: "Paste this into your OpenClaw config"
3. **Connection Status:**
   - Show if an OpenClaw Application Password exists
   - Show when it was created
   - Show last used date (if available from WP)
4. **Revoke Connection:**
   - One-click revoke button with confirmation
   - Cleans up the Application Password

## Technical Details
- Use WordPress Application Passwords API (built into WP 5.6+)
- Application password name: "OpenClaw" (or "OpenClaw - [sitename]")
- Minimum WP version: 5.6
- No external dependencies
- Clean, modern admin UI (use WP admin styles, no frameworks)
- Proper nonces and capability checks (manage_options)
- i18n ready (text domain: clawpress)

## File Structure
```
clawpress/
├── clawpress.php          # Main plugin file, hooks, activation
├── includes/
│   ├── class-clawpress-admin.php    # Admin page rendering
│   └── class-clawpress-api.php      # Application Password management
├── assets/
│   ├── css/
│   │   └── admin.css      # Admin page styles
│   └── js/
│       └── admin.js        # Copy button, AJAX revoke, wizard UX
├── readme.txt              # WordPress.org readme
└── SPEC.md
```

## Design Notes
- Keep it simple. This is a wizard, not a dashboard.
- The password display should feel important/secure — show it once, make it clear it won't be shown again.
- Use the OpenClaw lobster branding color (#E74C3C) as accent.
- Success/error states should be clear and friendly.
