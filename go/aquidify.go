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
//
// Your own task, any industry: register it once, then interpret with its id.
//
//	c.PutTask(ctx, "support.ticket@1.0.0", aquidify.TaskDefinition{
//		Instructions: "Classify customer support emails.",
//		Schema:       json.RawMessage(`{"type":"object","properties":{"category":{"enum":["billing","bug"]}}}`),
//	})
//	r, err := c.Interpret(ctx, aquidify.Request{Domain: "support.ticket@1.0.0", Input: body, Locale: "en"})
package aquidify

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

const Version = "0.2.0"

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
	var out Response
	if err := c.do(ctx, http.MethodPost, "/v1/interpret", req, &out, opts...); err != nil {
		return nil, err
	}
	return &out, nil
}

// TaskDefinition is what you register: instructions, a JSON Schema of the
// fields (top level object; keywords type, description, enum, properties,
// required, items, anyOf) and up to 5 examples.
type TaskDefinition struct {
	Instructions string          `json:"instructions"`
	Schema       json.RawMessage `json:"schema"`
	Description  string          `json:"description,omitempty"`
	Examples     []TaskExample   `json:"examples,omitempty"`
}

type TaskExample struct {
	Input  string          `json:"input"`
	Output json.RawMessage `json:"output"`
}

// Task is a registered task version.
type Task struct {
	TaskDefinition
	ID            string          `json:"id"`
	CreatedAt     time.Time       `json:"created_at"`
	ParserVersion string          `json:"parser_version"`
	OutputSchema  json.RawMessage `json:"output_schema"`
}

// PutTask registers a task version. The same definition again is a no-op; a
// changed one fails with code task_exists: register a new version.
func (c *Client) PutTask(ctx context.Context, id string, def TaskDefinition) (*Task, error) {
	var t Task
	if err := c.do(ctx, http.MethodPut, "/v1/tasks/"+url.PathEscape(id), def, &t); err != nil {
		return nil, err
	}
	return &t, nil
}

// GetTask returns a registered task; code not_found when this key has none by that id.
func (c *Client) GetTask(ctx context.Context, id string) (*Task, error) {
	var t Task
	if err := c.do(ctx, http.MethodGet, "/v1/tasks/"+url.PathEscape(id), nil, &t); err != nil {
		return nil, err
	}
	return &t, nil
}

// ListTasks returns the task ids registered with this API key.
func (c *Client) ListTasks(ctx context.Context) ([]string, error) {
	var out struct {
		Tasks []string `json:"tasks"`
	}
	if err := c.do(ctx, http.MethodGet, "/v1/tasks", nil, &out); err != nil {
		return nil, err
	}
	return out.Tasks, nil
}

func (c *Client) do(ctx context.Context, method, path string, in, out any, opts ...Option) error {
	var body io.Reader
	if in != nil {
		b, err := json.Marshal(in)
		if err != nil {
			return err
		}
		body = bytes.NewReader(b)
	}
	hr, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(c.BaseURL, "/")+path, body)
	if err != nil {
		return err
	}
	hr.Header.Set("Authorization", "Bearer "+c.apiKey)
	if in != nil {
		hr.Header.Set("Content-Type", "application/json")
	}
	hr.Header.Set("Accept", "application/json")
	hr.Header.Set("User-Agent", "aquidify-sdk-go/"+Version)
	for _, o := range opts {
		o(hr)
	}

	res, err := c.HTTP.Do(hr)
	if err != nil {
		return &Error{Code: "network", Message: err.Error()}
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	if err != nil {
		return &Error{Status: res.StatusCode, Code: "network", Message: err.Error()}
	}

	if res.StatusCode >= 200 && res.StatusCode < 300 {
		if err := json.Unmarshal(raw, out); err != nil {
			return fmt.Errorf("aquidify: decode response: %w", err)
		}
		return nil
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
	return apiErr
}
