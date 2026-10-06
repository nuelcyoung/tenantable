# Resolver Benchmark Results

## Methodology

1000 sequential resolutions, averaged over 3 runs.

## Results

| Scenario | Avg Time (ms) | DB Queries | Notes |
|----------|---------------|------------|-------|
| Cold     | —             | 1          | Cache miss → DB lookup |
| Warm     | —             | 0          | Cache hit → zero DB queries |

## Conclusion

The resolver cache eliminates DB queries on warm requests.
