# Verification Matrix — v0.1.0

The smoke-test matrix for v0.1.0 is **pending** execution. It will run as part of the vyos.dev cutover under [IS-474](https://vyos.atlassian.net/browse/IS-474), or as a follow-up if the operator stands up a dedicated local Phorge dev environment beforehand.

## Planned procedure

Each release will be smoke-tested against three backends in Docker:

| Backend | Image | Purpose |
|---------|-------|---------|
| Elasticsearch | `docker.elastic.co/elasticsearch/elasticsearch:7.17.24` | Primary target for IS-474 |
| Elasticsearch | `docker.elastic.co/elasticsearch/elasticsearch:8.15.x` | Current ES major |
| OpenSearch | `opensearchproject/opensearch:2.17.x` | OpenSearch wire-compat |

For each backend, the procedure is:

1. Start container (with security disabled for ES 8.x / OpenSearch).
2. Configure Phorge `cluster.search` with `type: elasticsearch-modern`, the appropriate `version` (7 / 8 / 7), and explicit `hosts[].roles`.
3. `bin/search init` — verify the index is created with the typeless mapping shape (`mappings.properties`, `documentType` keyword field present, no `include_in_all`).
4. `bin/search index --all` — verify documents are indexed without errors.
5. Hit `/search/?query=test` in the web UI — verify hits and ranking.
6. Edit a task, save — verify the document is updated in ES (incremental indexing).
7. Exercise the `exclude` saved-query parameter via a one-off `scripts/verify-exclude.php` REPL script — confirm the wire-level outbound body contains `bool.must_not.ids` and no `not` key.

## Results

Will be populated per release tag once the matrix runs. The latest release with completed verification will list:

- Date of run
- Phorge commit + extension commit tested against
- Per-backend PASS/FAIL with relevant evidence (logs, screenshots, mapping JSON)

## Status: v0.1.0

Pending. See [IS-474](https://vyos.atlassian.net/browse/IS-474) for the cutover execution; results land here when complete.
