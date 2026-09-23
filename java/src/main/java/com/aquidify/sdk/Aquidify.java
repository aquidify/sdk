package com.aquidify.sdk;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ObjectNode;

import java.io.IOException;
import java.net.URI;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;
import java.util.Set;

/**
 * Aquidify API client.
 *
 * <pre>{@code
 * var aq = new Aquidify(System.getenv("AQUIDIFY_API_KEY"));
 * var r = aq.interpret("hiring.candidate", "Iščem delo v skladišču v Ljubljani, brez nočnih.", "sl-SI");
 * r.interpretation().at("/intents/0/roles/0/value").asText(); // "warehouse"
 *
 * // your own task, any industry: register it once, then interpret with its id
 * aq.putTask("support.ticket@1.0.0", Map.of(
 *     "instructions", "Classify customer support emails.",
 *     "schema", Map.of("type", "object", "properties", Map.of(
 *         "category", Map.of("enum", List.of("billing", "bug"))))));
 * aq.interpret("support.ticket@1.0.0", body, "en");
 * }</pre>
 */
public final class Aquidify {
    public static final String VERSION = "0.2.0";

    private static final ObjectMapper JSON = new ObjectMapper();
    private final String apiKey;
    private final String baseUrl;
    private final Duration timeout;
    private final HttpClient http = HttpClient.newHttpClient();

    public Aquidify(String apiKey) {
        this(apiKey, "https://api.aquidify.com", Duration.ofSeconds(60));
    }

    public Aquidify(String apiKey, String baseUrl, Duration timeout) {
        String key = apiKey != null ? apiKey : System.getenv("AQUIDIFY_API_KEY");
        if (key == null || key.isEmpty()) {
            throw new IllegalArgumentException("Aquidify API key missing: pass it or set AQUIDIFY_API_KEY.");
        }
        this.apiKey = key;
        this.baseUrl = baseUrl.replaceAll("/+$", "");
        this.timeout = timeout;
    }

    /** Result of an interpretation. {@code clarification} is null unless the input cannot be interpreted without asking. */
    public record Response(String requestId, String domain, String parserVersion, String schemaVersion,
                           JsonNode interpretation, JsonNode clarification, JsonNode meta) {}

    public Response interpret(String domain, String input, String locale) {
        return interpret(domain, input, locale, null, null, null);
    }

    /** Interpret free text into structured intent. Versions and idempotencyKey may be null. */
    public Response interpret(String domain, String input, String locale,
                              String parserVersion, String schemaVersion, String idempotencyKey) {
        ObjectNode body = JSON.createObjectNode().put("domain", domain).put("input", input).put("locale", locale);
        if (parserVersion != null) body.put("parser_version", parserVersion);
        if (schemaVersion != null) body.put("schema_version", schemaVersion);

        JsonNode data = send("POST", "/v1/interpret", body, idempotencyKey);
        return new Response(data.path("request_id").asText(), data.path("domain").asText(),
                data.path("parser_version").asText(), data.path("schema_version").asText(),
                data.path("interpretation"), data.path("clarification").isNull() ? null : data.path("clarification"),
                data.path("meta"));
    }

    /**
     * Register a task version ({@code name@version}). The definition is the task's JSON
     * (description, instructions, schema, examples) as a Map, record or JsonNode. The same
     * definition again is a no-op; a changed one fails with code {@code task_exists}:
     * register a new version instead. Returns the registered task.
     */
    public JsonNode putTask(String id, Object definition) {
        return send("PUT", "/v1/tasks/" + pathSegment(id), JSON.valueToTree(definition), null);
    }

    /** A registered task; code {@code not_found} when this key has none by that id. */
    public JsonNode getTask(String id) {
        return send("GET", "/v1/tasks/" + pathSegment(id), null, null);
    }

    /** The task ids registered with this API key. */
    public List<String> listTasks() {
        List<String> ids = new ArrayList<>();
        send("GET", "/v1/tasks", null, null).path("tasks").forEach(t -> ids.add(t.asText()));
        return ids;
    }

    private static String pathSegment(String id) {
        return URLEncoder.encode(id, StandardCharsets.UTF_8).replace("+", "%20").replace("%40", "@");
    }

    private JsonNode send(String method, String path, JsonNode body, String idempotencyKey) {
        HttpRequest.Builder req = HttpRequest.newBuilder(URI.create(baseUrl + path))
                .timeout(timeout)
                .header("Authorization", "Bearer " + apiKey)
                .header("Accept", "application/json")
                .header("User-Agent", "aquidify-sdk-java/" + VERSION)
                .method(method, body == null ? HttpRequest.BodyPublishers.noBody()
                        : HttpRequest.BodyPublishers.ofString(body.toString()));
        if (body != null) req.header("Content-Type", "application/json");
        if (idempotencyKey != null) req.header("Idempotency-Key", idempotencyKey);

        HttpResponse<String> res;
        try {
            res = http.send(req.build(), HttpResponse.BodyHandlers.ofString());
        } catch (IOException e) {
            throw new AquidifyException("Aquidify request failed: " + e.getMessage(), 0, "network", null, null);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new AquidifyException("Aquidify request interrupted", 0, "network", null, null);
        }

        JsonNode data;
        try {
            data = JSON.readTree(res.body());
        } catch (IOException e) {
            data = JSON.createObjectNode();
        }
        if (data == null || data.isMissingNode()) data = JSON.createObjectNode();
        if (res.statusCode() >= 200 && res.statusCode() < 300) {
            return data;
        }
        Integer retryAfter = res.headers().firstValue("Retry-After").map(v -> {
            try { return Integer.valueOf(v); } catch (NumberFormatException e) { return null; }
        }).orElse(null);
        String requestId = data.hasNonNull("request_id") ? data.get("request_id").asText()
                : res.headers().firstValue("X-Request-ID").orElse(null);
        throw new AquidifyException(
                data.at("/error/message").asText("Aquidify returned HTTP " + res.statusCode()),
                res.statusCode(), data.at("/error/code").asText("unknown"), requestId, retryAfter);
    }

    /** A failed call. {@code code} is the API's stable error code, or "network". */
    public static final class AquidifyException extends RuntimeException {
        private static final Set<String> RETRYABLE = Set.of("rate_limited", "model_unavailable", "timeout", "network");
        public final int status;
        public final String code;
        public final String requestId;
        public final Integer retryAfter;

        AquidifyException(String message, int status, String code, String requestId, Integer retryAfter) {
            super(message);
            this.status = status;
            this.code = code;
            this.requestId = requestId;
            this.retryAfter = retryAfter;
        }

        public boolean isRetryable() { return RETRYABLE.contains(code); }
    }
}
