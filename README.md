# phorge-elasticsearch-modern

A Phorge full-text search extension that adds support for Elasticsearch 7.x, 8.x, and OpenSearch 1.x / 2.x / 3.x via the typeless API. The bundled Phorge `elasticsearch` engine remains the right choice for ES 5.x installs; this extension is a parallel option for everyone past that.

## Status

v0.1.0 — initial release. No auth support yet (deferred to v0.2). See the [Limitations](#limitations) section for the full list.

## Compatibility

| Backend | Tested | Notes |
|---------|--------|-------|
| Elasticsearch 7.17 | ✓ | Primary supported version. |
| Elasticsearch 8.x | ✓ | Run with `xpack.security.enabled=false` until v0.2 ships auth support. |
| OpenSearch 2.x | ✓ | Run with `plugins.security.disabled=true` for the same reason. |
| OpenSearch 1.x | (expected, untested) | Same typeless API surface; should work. |
| OpenSearch 3.x | (expected, untested) | Same caveat — please report success/issues. |
| Elasticsearch 5.x, 6.x | ✗ | Use the bundled Phorge engine. |

## Install

1. Clone alongside your Phorge checkout:

   ```sh
   cd /path/to/phorge/..
   git clone https://github.com/vyos/phorge-elasticsearch-modern.git
   ```

2. Add the library to Phorge's `local.json` (`load-libraries` is a flat array of paths):

   ```json
   {
     "load-libraries": ["../phorge-elasticsearch-modern/src/"]
   }
   ```

3. Add a `cluster.search` entry pointing at your modern cluster. Every host requires explicit `roles` — Phorge does not apply defaults.

   ```json
   {
     "cluster.search": [
       {
         "type": "elasticsearch-modern",
         "hosts": [
           {
             "host": "es.example.com",
             "port": 9200,
             "protocol": "http",
             "roles": {"read": true, "write": true}
           }
         ],
         "version": 7,
         "path": "phabricator/"
       }
     ]
   }
   ```

   For OpenSearch, set `"version": 7`. OpenSearch's own version numbers (1.x / 2.x / 3.x) are unrelated to this config field — `7` selects the typeless code path.

4. Initialize the index:

   ```sh
   ./bin/search init
   ```

5. Populate from MySQL:

   ```sh
   ./bin/search index --all
   ```

## Migration from ES 5 (parallel-write cutover)

Phorge writes search updates to **every writable service** in `cluster.search`. This lets you run the new and old clusters side-by-side during migration with no search-downtime window. **Important:** if any writable service errors, normal task edits will fail with an aggregate exception. Health-check the new cluster before adding it as writable.

1. Stand up the new cluster alongside the existing ES 5 cluster.
2. Health-check it: `curl http://new-cluster:9200/_cluster/health` returns `green` or `yellow`.
3. Install this extension per the steps above.
4. Add the new `cluster.search` entry with `roles: {"read": false, "write": true}` (writes only, no reads yet).
5. Run `./bin/search init`. Snapshot the ES 5 cluster's settings first — `bin/search init` iterates all writable services and may reinit ES 5 if it's flagged as insane.
6. Run `./bin/search index --all`. Both clusters fill from MySQL; ongoing writes fan out to both.
7. Flip the new entry to `roles: {"read": true, "write": true}` once you're satisfied it's healthy.
8. Drop the ES 5 entry's read role: `roles: {"read": false, "write": true}`. Reads go exclusively to the new cluster; writes still mirror to both as insurance.
9. **Rollback:**
   - *Before step 8* (ES 5 still has `"read": true`): flip the new entry to `{"read": false, "write": false}` or remove it entirely. ES 5 resumes serving all reads.
   - *After step 8* (ES 5 has `"read": false`): restore ES 5's read role to `true` **first** (or simultaneously), then flip the new entry to `{"read": false, "write": false}`. Doing it in the reverse order briefly leaves no read-capable host and search will return errors for in-flight requests.
10. After a soak period (1–2 weeks suggested), remove the ES 5 entry entirely and decommission that cluster.

## Limitations

- **No authentication in v0.1.** ES 8 and OpenSearch enable security by default; run with security disabled until v0.2 ships auth, or front the cluster with a reverse proxy that handles auth.
- **No `timeout` config key.** Phorge's `cluster.search` schema doesn't accept `timeout`; the extension uses Phorge's default HTTP timeout. Adding `timeout` requires a small upstream schema change, candidate for the v0.2 release.
- **`load-libraries` is sensitive to ordering.** If you load this extension before Phorge's core libraries, autodiscovery may fail. Phorge's own libraries load implicitly first in the documented setup; the example above is correct.
- **Phorge's extension API is not formally stable.** The upstream docs explicitly warn that extension APIs can break. Pin to a tested Phorge commit if stability matters.

## Verification

Each release is smoke-tested against the matrix in [`VERIFICATION.md`](VERIFICATION.md). The verification procedure spins up Docker containers for each supported backend, runs `bin/search init` + `bin/search index --all`, verifies the index mapping shape, and exercises the search and incremental-indexing paths.

## License

Apache-2.0. See [LICENSE](LICENSE).
