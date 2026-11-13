# Controlled security demonstration plan

This folder is intentionally documentation-only in the starter package. It keeps insecure experiments separate from the working attendance application.

Use a local copy, fictional data and a test database only. Never expose intentionally vulnerable code to the internet or connect it to real employee records.

Suggested demonstrations for the project presentation:

1. SQL injection: compare an unsafe string-concatenated query in a disposable lab with a PDO prepared statement. Show that parameterized input is treated as data.
2. XSS: enter a harmless HTML/script test string into a test profile. Compare raw output with `htmlspecialchars` output.
3. Broken access control: attempt to request an administrator route while signed in as an employee. Confirm server-side role checks deny access.
4. Brute force: simulate repeated incorrect PIN attempts in a local test harness. Demonstrate rate limiting, progressive delay and security logging.
5. CSRF: submit a state-changing request without a valid CSRF token and confirm the server rejects it.
6. Session security: compare session IDs before and after authentication; verify logout expires the cookie and destroys server-side session state.
7. IDOR: change a record identifier in a request and confirm users can only access records they are authorized to view.
8. Availability: use a small, controlled local request test to demonstrate rate limiting. Do not run denial-of-service tests against public systems.

The live application should remain protected. Demonstrate vulnerabilities with isolated examples and document the remediation and retest evidence.
