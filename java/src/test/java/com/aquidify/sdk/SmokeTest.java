package com.aquidify.sdk;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;

import java.time.Duration;

import static org.junit.jupiter.api.Assertions.*;

/** Live smoke test, no model calls (free): AQUIDIFY_API_KEY=... [AQUIDIFY_BASE_URL=...] mvn test */
@EnabledIfEnvironmentVariable(named = "AQUIDIFY_API_KEY", matches = ".+")
class SmokeTest {
    private static final String BASE = System.getenv().getOrDefault("AQUIDIFY_BASE_URL", "https://api.aquidify.com");

    private static Aquidify client(String key) {
        return new Aquidify(key, BASE, Duration.ofSeconds(30));
    }

    @Test
    void deterministic() {
        var r = client(null).interpret("hiring.candidate", "Ljubljana", "sl-SI");
        assertEquals("deterministic", r.meta().path("source").asText());
        assertEquals("Ljubljana", r.interpretation().at("/intents/0/locations/0/value").asText());
        assertNull(r.clarification());
    }

    @Test
    void unauthorized() {
        var e = assertThrows(Aquidify.AquidifyException.class,
                () -> client("wrong-key").interpret("hiring.candidate", "x", "sl"));
        assertEquals(401, e.status);
        assertEquals("unauthorized", e.code);
        assertFalse(e.isRetryable());
    }

    @Test
    void invalidRequest() {
        var e = assertThrows(Aquidify.AquidifyException.class,
                () -> client(null).interpret("no.such.domain", "x", "sl"));
        assertEquals(422, e.status);
        assertEquals("invalid_request", e.code);
        assertNotNull(e.requestId);
    }
}
