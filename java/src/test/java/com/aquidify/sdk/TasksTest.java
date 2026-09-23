package com.aquidify.sdk;

import com.sun.net.httpserver.HttpServer;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

import static org.junit.jupiter.api.Assertions.*;

/** Task calls against a local stand-in for the API: offline, no key needed. */
class TasksTest {
    private HttpServer server;
    private final Map<String, String> seen = new ConcurrentHashMap<>();

    @BeforeEach
    void start() throws IOException {
        server = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        server.createContext("/v1/tasks", ex -> {
            String req = ex.getRequestMethod() + " " + ex.getRequestURI().getRawPath();
            seen.put(req, new String(ex.getRequestBody().readAllBytes(), StandardCharsets.UTF_8));
            seen.put(req + " auth", String.valueOf(ex.getRequestHeaders().getFirst("Authorization")));
            String body;
            int status = 200;
            switch (req) {
                case "PUT /v1/tasks/support.ticket@1.0.0", "GET /v1/tasks/support.ticket@1.0.0" ->
                        body = "{\"id\":\"support.ticket@1.0.0\",\"parser_version\":\"1.0.0+abc\"}";
                case "GET /v1/tasks" -> body = "{\"tasks\":[\"support.ticket@1.0.0\",\"booking@2.0.0\"]}";
                default -> {
                    status = 404;
                    body = "{\"request_id\":\"r1\",\"error\":{\"code\":\"not_found\",\"message\":\"no such task for this API key\"}}";
                }
            }
            byte[] out = body.getBytes(StandardCharsets.UTF_8);
            ex.getResponseHeaders().set("Content-Type", "application/json");
            ex.sendResponseHeaders(status, out.length);
            ex.getResponseBody().write(out);
            ex.close();
        });
        server.start();
    }

    @AfterEach
    void stop() {
        server.stop(0);
    }

    private Aquidify client() {
        return new Aquidify("k", "http://127.0.0.1:" + server.getAddress().getPort() + "/", Duration.ofSeconds(5));
    }

    @Test
    void putSendsTheDefinitionAndReturnsTheTask() {
        var task = client().putTask("support.ticket@1.0.0", Map.of(
                "instructions", "Classify customer support emails.",
                "schema", Map.of("type", "object", "properties", Map.of("category", Map.of("enum", List.of("billing", "bug"))))));
        assertEquals("1.0.0+abc", task.path("parser_version").asText());
        String sent = seen.get("PUT /v1/tasks/support.ticket@1.0.0");
        assertTrue(sent.contains("\"instructions\":\"Classify customer support emails.\""), sent);
        assertTrue(sent.contains("\"enum\":[\"billing\",\"bug\"]"), sent);
        assertEquals("Bearer k", seen.get("PUT /v1/tasks/support.ticket@1.0.0 auth"));
    }

    @Test
    void getAndList() {
        assertEquals("support.ticket@1.0.0", client().getTask("support.ticket@1.0.0").path("id").asText());
        assertEquals(List.of("support.ticket@1.0.0", "booking@2.0.0"), client().listTasks());
    }

    @Test
    void unknownTaskIsNotFound() {
        var e = assertThrows(Aquidify.AquidifyException.class, () -> client().getTask("nope@1.0.0"));
        assertEquals(404, e.status);
        assertEquals("not_found", e.code);
        assertEquals("r1", e.requestId);
        assertFalse(e.isRetryable());
    }
}
