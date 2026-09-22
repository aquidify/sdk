package aquidify

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"testing"
)

// Live smoke test, no model calls (free):
//
//	AQUIDIFY_API_KEY=... [AQUIDIFY_BASE_URL=...] go test ./...
func client(t *testing.T, key string) *Client {
	t.Helper()
	if os.Getenv("AQUIDIFY_API_KEY") == "" {
		t.Skip("set AQUIDIFY_API_KEY")
	}
	c, err := New(key)
	if err != nil {
		t.Fatal(err)
	}
	if u := os.Getenv("AQUIDIFY_BASE_URL"); u != "" {
		c.BaseURL = u
	}
	return c
}

func TestDeterministic(t *testing.T) {
	r, err := client(t, "").Interpret(context.Background(), Request{Domain: "hiring.candidate", Input: "Ljubljana", Locale: "sl-SI"})
	if err != nil {
		t.Fatal(err)
	}
	var in struct {
		Intents []struct {
			Locations []struct {
				Value *string `json:"value"`
			} `json:"locations"`
		} `json:"intents"`
	}
	if err := json.Unmarshal(r.Interpretation, &in); err != nil {
		t.Fatal(err)
	}
	if r.Meta.Source != "deterministic" || r.Clarification != nil || *in.Intents[0].Locations[0].Value != "Ljubljana" {
		t.Fatalf("unexpected: %+v %s", r.Meta, r.Interpretation)
	}
}

func TestErrors(t *testing.T) {
	cases := []struct {
		key, domain string
		status      int
		code        string
	}{
		{"wrong-key", "hiring.candidate", 401, "unauthorized"},
		{"", "no.such.domain", 422, "invalid_request"},
	}
	for _, tc := range cases {
		_, err := client(t, tc.key).Interpret(context.Background(), Request{Domain: tc.domain, Input: "x", Locale: "sl"})
		var e *Error
		if !errors.As(err, &e) || e.Status != tc.status || e.Code != tc.code || e.RequestID == "" || e.Retryable() {
			t.Fatalf("%s: got %v", tc.code, err)
		}
	}
}
