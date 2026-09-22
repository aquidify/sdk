// Package aquidify is the Go client for the Aquidify API: turn what people type
// into validated, structured intent.
//
//	c, _ := aquidify.New("")  // reads AQUIDIFY_API_KEY
//	r, err := c.Interpret(ctx, aquidify.Request{
//		Domain: "hiring.candidate",
//		Input:  "Iščem delo v skladišču v Ljubljani, brez nočnih.",
//		Locale: "sl-SI",
//	})
//
// Interpretation is returned as raw JSON; unmarshal it into your own types or
// see schemas/hiring.candidate-1.0.0.json in the repository.
package aquidify

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
)

const Version = "0.1.0"

type Request struct {
	Domain        string `json:"domain"`
	Input         string `json:"input"`
	Locale        string `json:"locale"`
	ParserVersion string `json:"parser_version,omitempty"`
	SchemaVersion string `json:"schema_version,omitempty"`
}

type Response struct {
	RequestID      string          `json:"request_id"`
	Domain         string          `json:"domain"`
	ParserVersion  string          `json:"parser_version"`
	SchemaVersion  string          `json:"schema_version"`
	Interpretation json.RawMessage `json:"interpretation"`
	Clarification  *Clarification  `json:"clarification"`
	Meta           Meta            `json:"meta"`
}

type Clarification struct {
	Reason           string   `json:"reason"`
	Field            string   `json:"field"`
	QuestionKey      string   `json:"question_key"`
	SuggestedOptions []string `json:"suggested_options"`
}

type Meta struct {
	Source    string `json:"source"` // deterministic | ai
	Cache     string `json:"cache"`  // bypass | hit | miss | coalesced
	Provider  string `json:"provider,omitempty"`
	Model     string `json:"model,omitempty"`
	LatencyMS int64  `json:"latency_ms"`
}

// Error is a failed call. Code is the API's stable error code, or "network".
type Error struct {
	Status     int
	Code       string
	Message    string
	RequestID  string
	RetryAfter time.Duration
}

func (e *Error) Error() string {
	return fmt.Sprintf("aquidify: %d %s: %s", e.Status, e.Code, e.Message)
}

// Retryable reports whether the same request may succeed later.
func (e *Error) Retryable() bool {
	switch e.Code {
	case "rate_limited", "model_unavailable", "timeout", "network":
		return true
	}
	return false
}

type Client struct {
	apiKey  string
	BaseURL string
	HTTP    *http.Client
}

// New creates a client. An empty apiKey reads AQUIDIFY_API_KEY.
func New(apiKey string) (*Client, error) {
	if apiKey == "" {
		apiKey = os.Getenv("AQUIDIFY_API_KEY")
	}
	if apiKey == "" {
		return nil, errors.New("aquidify: API key missing: pass it or set AQUIDIFY_API_KEY")
	}
	return &Client{apiKey: apiKey, BaseURL: "https://api.aquidify.com", HTTP: &http.Client{Timeout: 60 * time.Second}}, nil
}

// Option adjusts a single call.
type Option func(*http.Request)

// WithIdempotencyKey replays the first response for the same key and body for 24h.
func WithIdempotencyKey(key string) Option {
	return func(r *http.Request) { r.Header.Set("Idempotency-Key", key) }
}

// Interpret turns free text into structured intent.
func (c *Client) Interpret(ctx context.Context, req Request, opts ...Option) (*Response, error) {
	body, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}
	hr, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.BaseURL, "/")+"/v1/interpret", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	hr.Header.Set("Authorization", "Bearer "+c.apiKey)
	hr.Header.Set("Content-Type", "application/json")
	hr.Header.Set("Accept", "application/json")
	hr.Header.Set("User-Agent", "aquidify-sdk-go/"+Version)
	for _, o := range opts {
		o(hr)
	}

	res, err := c.HTTP.Do(hr)
	if err != nil {
		return nil, &Error{Code: "network", Message: err.Error()}
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	if err != nil {
		return nil, &Error{Status: res.StatusCode, Code: "network", Message: err.Error()}
	}

	if res.StatusCode >= 200 && res.StatusCode < 300 {
		var out Response
		if err := json.Unmarshal(raw, &out); err != nil {
			return nil, fmt.Errorf("aquidify: decode response: %w", err)
		}
		return &out, nil
	}

	var e struct {
		Error struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		} `json:"error"`
		RequestID string `json:"request_id"`
	}
	_ = json.Unmarshal(raw, &e)
	apiErr := &Error{Status: res.StatusCode, Code: e.Error.Code, Message: e.Error.Message, RequestID: e.RequestID}
	if apiErr.Code == "" {
		apiErr.Code, apiErr.Message = "unknown", fmt.Sprintf("HTTP %d", res.StatusCode)
	}
	if apiErr.RequestID == "" {
		apiErr.RequestID = res.Header.Get("X-Request-ID")
	}
	if s, err := strconv.Atoi(res.Header.Get("Retry-After")); err == nil {
		apiErr.RetryAfter = time.Duration(s) * time.Second
	}
	return nil, apiErr
}
