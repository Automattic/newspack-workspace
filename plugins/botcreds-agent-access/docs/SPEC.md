# Agent Access — WordPress Plugin

## Purpose
A WordPress plugin that provides a wp-admin wizard for creating and revoking Agent Access Application Passwords. Makes it dead simple for non-technical users to connect Agent Access to their WordPress site.

## MVP Features
1. **wp-admin menu page** under Settings → Agent Access
2. **Create Connection wizard:**
   - One-click button to generate an Application Password named "Agent Access"
   - Display the generated password ONCE with a copy button
   - Show the site URL + username + password in a ready-to-paste format (or copyable JSON)
   - Clear instructions: "Paste this into your Agent Access config"
3. **Connection Status:**
   - Show if an Agent Access Application Password exists
   - Show when it was created
   - Show last used date (if available from WP)
4. **Revoke Connection:**
   - One-click revoke button with confirmation
   - Cleans up the Application Password

## Technical Details
- Use WordPress Application Passwords API (built into WP 5.6+)
- Application password name: "Agent Access" (or "Agent Access - [sitename]")
- Minimum WP version: 5.6
- No external dependencies
- Clean, modern admin UI (use WP admin styles, no frameworks)
- Proper nonces and capability checks (manage_options)
- i18n ready (text domain: agent-access)

## File Structure
```
agent-access/
├── agent-access.php          # Main plugin file, hooks, activation
├── includes/
│   ├── class-agent-access-admin.php    # Admin page rendering
│   └── class-agent-access-api.php      # Application Password management
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
- Use the Agent Access lobster branding color (#E74C3C) as accent.
- Success/error states should be clear and friendly.
