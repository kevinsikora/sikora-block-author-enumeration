#!/usr/bin/env bash
# Run the automated checks from TESTING.md and write an HTML report.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPORT_PATH="${SCRIPT_DIR}/test-report.html"
AUTHOR_SLUG="your-author-slug"
INCLUDE_OPTIONAL=0

usage() {
	cat <<'EOF'
Usage: ./run-tests.sh <site-url> [author-slug] [--optional]

  site-url      Base URL of the WordPress site (e.g. https://example.com)
  author-slug   Author archive slug for archive tests (default: your-author-slug)
  --optional    Also run the "plugin deactivated" leak check from TESTING.md §1

Example:
  ./run-tests.sh https://mysite.com jane
EOF
}

if [[ $# -lt 1 ]]; then
	usage
	exit 2
fi

BASE_URL="${1%/}"
shift || true

while [[ $# -gt 0 ]]; do
	case "$1" in
		--optional) INCLUDE_OPTIONAL=1 ;;
		-h|--help) usage; exit 0 ;;
		*) AUTHOR_SLUG="$1" ;;
	esac
	shift
done

if [[ ! "${BASE_URL}" =~ ^https?:// ]]; then
	echo "Error: site-url must start with http:// or https://" >&2
	exit 2
fi

HOME_HOST="$(printf '%s' "${BASE_URL}" | sed -E 's#^https?://##' | cut -d/ -f1)"
PASSED=0
FAILED=0
SKIPPED=0
RESULTS=() # records use $'\x1f' field separators: status, name, expected, command, output

html_escape() {
	printf '%s' "$1" | sed \
		-e 's/&/\&amp;/g' \
		-e 's/</\&lt;/g' \
		-e 's/>/\&gt;/g' \
		-e 's/"/\&quot;/g'
}

record() {
	local status="$1" name="$2" expected="$3" command="$4" output="$5"
	RESULTS+=("${status}"$'\x1f'"${name}"$'\x1f'"${expected}"$'\x1f'"${command}"$'\x1f'"${output}")
	case "${status}" in
		PASS) PASSED=$((PASSED + 1)) ;;
		FAIL) FAILED=$((FAILED + 1)) ;;
		SKIP) SKIPPED=$((SKIPPED + 1)) ;;
	esac
	printf '[%s] %s\n' "${status}" "${name}"
}

run_capture() {
	# shellcheck disable=SC2086
	bash -c "$1" 2>&1 || true
}

# Location header points at site homepage (not /author/...).
location_is_homepage() {
	local headers="$1"
	local loc
	loc="$(printf '%s\n' "${headers}" | grep -i '^location:' | head -1 | sed -E 's/^[Ll]ocation:[[:space:]]*//;s/[[:space:]]*$//')"
	[[ -n "${loc}" ]] || return 1
	printf '%s' "${loc}" | grep -qi '/author/' && return 1
	# Accept absolute or host-relative home URLs.
	if [[ "${loc}" =~ ^https?:// ]]; then
		local loc_host loc_path
		loc_host="$(printf '%s' "${loc}" | sed -E 's#^https?://##' | cut -d/ -f1)"
		loc_path="$(printf '%s' "${loc}" | sed -E 's#^https?://[^/]+##')"
		[[ "${loc_path}" == "" || "${loc_path}" == "/" ]] || return 1
		[[ "$(printf '%s' "${loc_host}" | tr '[:upper:]' '[:lower:]')" == "$(printf '%s' "${HOME_HOST}" | tr '[:upper:]' '[:lower:]')" ]]
		return $?
	fi
	[[ "${loc}" == "/" ]]
}

has_status() {
	local headers="$1" code="$2"
	printf '%s\n' "${headers}" | grep -Eiq "^HTTP/[0-9.]+[[:space:]]+${code}([[:space:]]|$)"
}

echo "Testing ${BASE_URL}"
echo "Author slug: ${AUTHOR_SLUG}"
echo

# --- §1 optional (plugin deactivated) ---
if [[ "${INCLUDE_OPTIONAL}" -eq 1 ]]; then
	cmd="curl -sI \"${BASE_URL}/?author=1\" | grep -i -E \"^HTTP|^location\""
	out="$(run_capture "${cmd}")"
	if has_status "${out}" 301 && printf '%s' "${out}" | grep -qi '/author/'; then
		record "PASS" "Optional: leak visible with plugin off" "301 Location containing /author/" "${cmd}" "${out}"
	else
		record "FAIL" "Optional: leak visible with plugin off" "301 Location containing /author/ (plugin must be deactivated)" "${cmd}" "${out}"
	fi
else
	record "SKIP" "Optional: leak visible with plugin off" "Run with --optional after deactivating the plugin" "(not run)" ""
fi

# --- §2 main test ---
cmd="curl -sI \"${BASE_URL}/?author=1\" | grep -i -E \"^HTTP|^location\""
out="$(run_capture "${cmd}")"
if has_status "${out}" 301 && location_is_homepage "${out}"; then
	record "PASS" "Main test: ?author=1 redirects home" "301 to homepage, no /author/ in Location" "${cmd}" "${out}"
else
	record "FAIL" "Main test: ?author=1 redirects home" "301 to homepage, no /author/ in Location" "${cmd}" "${out}"
fi

# --- §3 bypass variants ---
for q in "author=1" "author=1a" "author=1,2" "author=%201" "author[]=1" "p=1&author=1"; do
	cmd="curl -gsI \"${BASE_URL}/?${q}\" | grep -i \"^location\" || echo \"NO REDIRECT\""
	out="$(run_capture "${cmd}")"
	if printf '%s' "${out}" | grep -qi 'NO REDIRECT'; then
		record "FAIL" "Bypass variant: ?${q}" "location: homepage" "${cmd}" "${out}"
	elif printf '%s' "${out}" | grep -qi '/author/'; then
		record "FAIL" "Bypass variant: ?${q}" "location: homepage (not /author/...)" "${cmd}" "${out}"
	elif location_is_homepage "${out}"; then
		record "PASS" "Bypass variant: ?${q}" "location: homepage" "${cmd}" "${out}"
	else
		record "FAIL" "Bypass variant: ?${q}" "location: homepage" "${cmd}" "${out}"
	fi
done

cmd="curl -sI \"${BASE_URL}/?author_name=admin\" | grep -i -E \"^HTTP|^location\""
out="$(run_capture "${cmd}")"
if has_status "${out}" 301 && location_is_homepage "${out}"; then
	record "PASS" "Slug bypass: ?author_name=admin" "301 to homepage, not /author/admin/" "${cmd}" "${out}"
else
	record "FAIL" "Slug bypass: ?author_name=admin" "301 to homepage, not /author/admin/" "${cmd}" "${out}"
fi

cmd="curl -si -X POST -d \"author=1\" \"${BASE_URL}/\" | grep -i -E \"^HTTP|^location\""
out="$(run_capture "${cmd}")"
if has_status "${out}" 301 && location_is_homepage "${out}"; then
	record "PASS" "POST author=1 redirects home" "301 to homepage" "${cmd}" "${out}"
else
	record "FAIL" "POST author=1 redirects home" "301 to homepage" "${cmd}" "${out}"
fi

# --- §4 REST, sitemap, oEmbed ---
cmd="curl -s -o /dev/null -w \"%{http_code}\\n\" \"${BASE_URL}/wp-json/wp/v2/users\""
out="$(run_capture "${cmd}")"
if [[ "$(printf '%s' "${out}" | tr -d '[:space:]')" == "404" ]]; then
	record "PASS" "REST /wp/v2/users hidden when logged out" "404" "${cmd}" "${out}"
else
	record "FAIL" "REST /wp/v2/users hidden when logged out" "404" "${cmd}" "${out}"
fi

cmd="curl -s -o /dev/null -w \"%{http_code}\\n\" \"${BASE_URL}/wp-sitemap-users-1.xml\""
out="$(run_capture "${cmd}")"
if [[ "$(printf '%s' "${out}" | tr -d '[:space:]')" == "404" ]]; then
	record "PASS" "Users sitemap disabled" "404" "${cmd}" "${out}"
else
	record "FAIL" "Users sitemap disabled" "404" "${cmd}" "${out}"
fi

cmd="curl -s \"${BASE_URL}/wp-json/oembed/1.0/embed?url=${BASE_URL}/\" | grep -E '\"author_url\"|\"author_name\"' || echo \"NO AUTHOR FIELDS\""
out="$(run_capture "${cmd}")"
if printf '%s' "${out}" | grep -q '"author_url"'; then
	record "FAIL" "oEmbed has no author_url" "no author_url (author_name optional if display name differs)" "${cmd}" "${out}"
else
	record "PASS" "oEmbed has no author_url" "no author_url (author_name optional if display name differs)" "${cmd}" "${out}"
fi

# --- §5 nothing else broke ---
cmd="curl -sI \"${BASE_URL}/author/${AUTHOR_SLUG}/\" | grep -i \"^HTTP\""
out="$(run_capture "${cmd}")"
if has_status "${out}" 200 || has_status "${out}" 404; then
	record "PASS" "Author archive still reachable" "200 (or 404 if no published posts)" "${cmd}" "${out}"
else
	record "FAIL" "Author archive still reachable" "200 (or 404 if no published posts)" "${cmd}" "${out}"
fi

cmd="curl -s -o /dev/null -w \"%{http_code}\\n\" \"${BASE_URL}/wp-json/wp/v2/posts?author=1\""
out="$(run_capture "${cmd}")"
code="$(printf '%s' "${out}" | tr -d '[:space:]')"
if [[ "${code}" == "200" ]]; then
	record "PASS" "REST posts?author=1 still works" "200 (not 301)" "${cmd}" "${out}"
else
	record "FAIL" "REST posts?author=1 still works" "200 (not 301)" "${cmd}" "${out}"
fi

cmd="curl -sI \"${BASE_URL}/?s=test\" | grep -i \"^HTTP\""
out="$(run_capture "${cmd}")"
if has_status "${out}" 200; then
	record "PASS" "Normal search page unaffected" "200" "${cmd}" "${out}"
else
	record "FAIL" "Normal search page unaffected" "200" "${cmd}" "${out}"
fi

# --- §6 dashboard (manual) ---
record "SKIP" "Dashboard: Posts author column filter" "Manual check in wp-admin" "(manual)" ""
record "SKIP" "Dashboard: Block editor author controls" "Manual check in wp-admin" "(manual)" ""
record "SKIP" "Dashboard: Users page loads" "Manual check in wp-admin" "(manual)" ""

# --- §7 debug log (manual / server) ---
record "SKIP" "No plugin errors in debug.log" "Check wp-content/debug.log on the server" "(manual)" ""

# --- Write HTML report ---
# Prefer the IANA zone name (e.g. America/Los_Angeles) over abbreviations like PDT.
timezone_name() {
	if [[ -n "${TZ:-}" ]]; then
		printf '%s' "${TZ}"
		return
	fi
	if [[ -L /etc/localtime ]]; then
		readlink /etc/localtime | sed -E 's|.*/zoneinfo/||'
		return
	fi
	if command -v timedatectl >/dev/null 2>&1; then
		timedatectl show -p Timezone --value 2>/dev/null && return
	fi
	date '+%z'
}

generated_at="$(date '+%Y-%m-%d %H:%M:%S') $(timezone_name)"
total=$((PASSED + FAILED + SKIPPED))

{
	cat <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sikora Block Author Enumeration — Test Report</title>
<style>
:root { --pass:#0a7a32; --fail:#b42318; --skip:#675400; --bg:#f6f7f9; --card:#fff; --border:#d8dce3; }
* { box-sizing: border-box; }
body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: #1a1d23; line-height: 1.45; }
main { max-width: 960px; margin: 2rem auto; padding: 0 1rem 3rem; }
h1 { font-size: 1.5rem; margin: 0 0 .35rem; }
.meta { color: #5b6472; margin-bottom: 1.25rem; }
.summary { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.pill { background: var(--card); border: 1px solid var(--border); border-radius: 999px; padding: .4rem .9rem; font-weight: 600; }
.pill.pass { color: var(--pass); }
.pill.fail { color: var(--fail); }
.pill.skip { color: var(--skip); }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; margin-bottom: .75rem; overflow: hidden; }
.head { display: flex; gap: .75rem; align-items: flex-start; padding: .85rem 1rem; border-bottom: 1px solid var(--border); }
.badge { font-size: .75rem; font-weight: 700; letter-spacing: .03em; padding: .2rem .5rem; border-radius: 6px; color: #fff; }
.badge.PASS { background: var(--pass); }
.badge.FAIL { background: var(--fail); }
.badge.SKIP { background: #a67c00; }
.name { font-weight: 600; }
.details { padding: .85rem 1rem 1rem; font-size: .92rem; }
.label { color: #5b6472; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; margin-top: .65rem; }
.label:first-child { margin-top: 0; }
pre { margin: .3rem 0 0; padding: .65rem .75rem; background: #f0f2f5; border-radius: 6px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
</style>
</head>
<body>
<main>
<h1>Sikora Block Author Enumeration — Test Report</h1>
<p class="meta">Site: <strong>$(html_escape "${BASE_URL}")</strong><br>
Author slug: <strong>$(html_escape "${AUTHOR_SLUG}")</strong><br>
Generated: ${generated_at}</p>
<div class="summary">
<span class="pill">Total ${total}</span>
<span class="pill pass">Passed ${PASSED}</span>
<span class="pill fail">Failed ${FAILED}</span>
<span class="pill skip">Skipped ${SKIPPED}</span>
</div>
EOF

	for row in "${RESULTS[@]}"; do
		IFS=$'\x1f' read -r status name expected command output <<<"${row}"
		cat <<EOF
<article class="card">
<div class="head">
<span class="badge ${status}">${status}</span>
<div class="name">$(html_escape "${name}")</div>
</div>
<div class="details">
<div class="label">Expected</div>
<pre>$(html_escape "${expected}")</pre>
<div class="label">Command</div>
<pre>$(html_escape "${command}")</pre>
<div class="label">Output</div>
<pre>$(html_escape "${output}")</pre>
</div>
</article>
EOF
	done

	cat <<EOF
</main>
</body>
</html>
EOF
} > "${REPORT_PATH}"

echo
echo "Report: ${REPORT_PATH}"
echo "Passed: ${PASSED}  Failed: ${FAILED}  Skipped: ${SKIPPED}"

if [[ "${FAILED}" -gt 0 ]]; then
	exit 1
fi
exit 0
