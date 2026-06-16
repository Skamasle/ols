package state

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

type DesiredState struct {
	SchemaVersion int      `json:"schemaVersion"`
	Generation    int      `json:"generation"`
	Server        Server   `json:"server"`
	Domains       []Domain `json:"domains"`
}

type Server struct {
	DefaultRouting string   `json:"defaultRouting"`
	Listener       Listener `json:"listener"`
}

type Listener struct {
	BindAddress string `json:"bindAddress"`
	Port        int    `json:"port"`
	Protocol    string `json:"protocol"`
}

type Domain struct {
	GUID             string        `json:"guid"`
	PleskID          int           `json:"pleskId"`
	Name             string        `json:"name"`
	Aliases          []string      `json:"aliases"`
	DocumentRoot     string        `json:"documentRoot"`
	VhostRoot        string        `json:"vhostRoot"`
	SystemUser       string        `json:"systemUser"`
	SystemGroup      string        `json:"systemGroup"`
	NativeProfile    NativeProfile `json:"nativeProfile"`
	PHP              PHP           `json:"php"`
	RequestedRouting string        `json:"requestedRouting"`
	AppliedRouting   string        `json:"appliedRouting"`
	CacheEnabled     *bool         `json:"cacheEnabled,omitempty"`
}

type NativeProfile struct {
	WebMode      string `json:"webMode"`
	ProxyMode    bool   `json:"proxyMode"`
	PhpHandlerID string `json:"phpHandlerId"`
}

type PHP struct {
	PleskHandlerID string `json:"pleskHandlerId"`
	Version        string `json:"version"`
	LsphpBinary    string `json:"lsphpBinary"`
	Socket         string `json:"socket"`
	Lsapi          LSAPI  `json:"lsapi"`
}

type LSAPI struct {
	MaxConnections       int  `json:"maxConnections"`
	Children             int  `json:"children"`
	Instances            int  `json:"instances"`
	Backlog              int  `json:"backlog"`
	InitTimeout          int  `json:"initTimeout"`
	RetryTimeout         int  `json:"retryTimeout"`
	PersistentConnection bool `json:"persistentConnection"`
	ResponseBuffering    bool `json:"responseBuffering"`
}

type Store struct {
	path string
}

func New(path string) *Store {
	return &Store{path: filepath.Clean(path)}
}

func (s *Store) Path() string {
	return s.path
}

func (s *Store) Load() (*DesiredState, error) {
	if "" == s.path {
		return nil, fmt.Errorf("state file path is required")
	}
	raw, err := os.ReadFile(s.path)
	if err != nil {
		return nil, err
	}
	var state DesiredState
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&state); err != nil {
		return nil, err
	}
	if err := ensureJSONEnd(decoder); err != nil {
		return nil, err
	}
	if err := validate(&state); err != nil {
		return nil, err
	}
	return &state, nil
}

func (s *Store) FindDomainForPath(state *DesiredState, changedPath string) (*Domain, string, bool) {
	if nil == state {
		return nil, "", false
	}
	cleaned := filepath.Clean(changedPath)
	var best *Domain
	var bestRoot string
	for i := range state.Domains {
		domain := &state.Domains[i]
		for _, root := range candidateRoots(domain) {
			if "" == root {
				continue
			}
			cleanRoot := filepath.Clean(root)
			if cleaned == cleanRoot || strings.HasPrefix(cleaned, cleanRoot+string(os.PathSeparator)) {
				if nil == best || len(cleanRoot) > len(bestRoot) {
					best = domain
					bestRoot = cleanRoot
				}
			}
		}
	}
	if nil == best {
		return nil, "", false
	}
	return best, bestRoot, true
}

func candidateRoots(domain *Domain) []string {
	return []string{
		domain.DocumentRoot,
		domain.VhostRoot,
	}
}

func ensureJSONEnd(decoder *json.Decoder) error {
	var extra interface{}
	if err := decoder.Decode(&extra); err != io.EOF {
		if err == nil {
			return fmt.Errorf("desired state contains trailing JSON data")
		}
		return err
	}
	return nil
}

func validate(value *DesiredState) error {
	if value.SchemaVersion != 1 {
		return fmt.Errorf("unsupported desired state schema version: %d", value.SchemaVersion)
	}
	if value.Generation < 0 {
		return fmt.Errorf("desired state generation cannot be negative")
	}
	if value.Server.DefaultRouting != "native" {
		return fmt.Errorf("server default routing must be native")
	}
	if value.Server.Listener.BindAddress != "127.0.0.1" &&
		value.Server.Listener.BindAddress != "::1" {
		return fmt.Errorf("listener bind address is invalid")
	}
	if value.Server.Listener.Port < 1024 || value.Server.Listener.Port > 65535 {
		return fmt.Errorf("listener port is invalid")
	}
	if value.Server.Listener.Protocol != "http" {
		return fmt.Errorf("listener protocol is invalid")
	}

	names := make(map[string]struct{}, len(value.Domains))
	guids := make(map[string]struct{}, len(value.Domains))
	for index := range value.Domains {
		domain := &value.Domains[index]
		if domain.Name == "" || domain.GUID == "" {
			return fmt.Errorf("domain %d identity is incomplete", index)
		}
		name := strings.ToLower(domain.Name)
		guid := strings.ToLower(strings.Trim(domain.GUID, "{}"))
		if _, exists := names[name]; exists {
			return fmt.Errorf("domain name is duplicated: %s", domain.Name)
		}
		if _, exists := guids[guid]; exists {
			return fmt.Errorf("domain GUID is duplicated: %s", domain.GUID)
		}
		names[name] = struct{}{}
		guids[guid] = struct{}{}

		if !filepath.IsAbs(domain.DocumentRoot) {
			return fmt.Errorf("domain %s document root is invalid", domain.Name)
		}
		if domain.VhostRoot != "" && !filepath.IsAbs(domain.VhostRoot) {
			return fmt.Errorf("domain %s vhost root is invalid", domain.Name)
		}
		if domain.RequestedRouting != "native" && domain.RequestedRouting != "ols" {
			return fmt.Errorf("domain %s requested routing is invalid", domain.Name)
		}
		if domain.AppliedRouting != "native" && domain.AppliedRouting != "ols" {
			return fmt.Errorf("domain %s applied routing is invalid", domain.Name)
		}
	}
	return nil
}
