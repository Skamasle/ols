package state

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

const (
	maxDomains = 10000
	maxAliases = 100
	stateRoot  = "/usr/local/psa/var/modules/skamasle-ols"
)

var (
	guidPattern    = regexp.MustCompile(`^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$`)
	domainPattern  = regexp.MustCompile(`^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)
	accountPattern = regexp.MustCompile(`^[a-z_][a-z0-9_.-]{0,63}$`)
	handlerPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`)
	versionPattern = regexp.MustCompile(`^\d+\.\d+$`)
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
	GUID                string        `json:"guid"`
	PleskID             int           `json:"pleskId"`
	Name                string        `json:"name"`
	Aliases             []string      `json:"aliases"`
	DocumentRoot        string        `json:"documentRoot"`
	VhostRoot           string        `json:"vhostRoot"`
	SystemUser          string        `json:"systemUser"`
	SystemGroup         string        `json:"systemGroup"`
	NativeProfile       NativeProfile `json:"nativeProfile"`
	PHP                 PHP           `json:"php"`
	RequestedRouting    string        `json:"requestedRouting"`
	AppliedRouting      string        `json:"appliedRouting"`
	CacheEnabled        *bool         `json:"cacheEnabled,omitempty"`
	CachePrivateEnabled *bool         `json:"cachePrivateEnabled,omitempty"`
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
	Lsapi          *LSAPI `json:"lsapi,omitempty"`
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
	if err := validateRawShape(raw); err != nil {
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

func AppliedOLSDocumentRoots(value *DesiredState) []string {
	if value == nil {
		return nil
	}
	roots := make([]string, 0, len(value.Domains))
	seen := make(map[string]struct{}, len(value.Domains))
	for index := range value.Domains {
		domain := &value.Domains[index]
		if domain.AppliedRouting != "ols" {
			continue
		}
		root := filepath.Clean(domain.DocumentRoot)
		if _, exists := seen[root]; exists {
			continue
		}
		seen[root] = struct{}{}
		roots = append(roots, root)
	}
	return roots
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

func validateRawShape(raw []byte) error {
	var root map[string]json.RawMessage
	if err := json.Unmarshal(raw, &root); err != nil {
		return err
	}
	if err := exactKeys(root, []string{"schemaVersion", "generation", "server", "domains"}, nil, "state"); err != nil {
		return err
	}
	var server map[string]json.RawMessage
	if err := json.Unmarshal(root["server"], &server); err != nil || server == nil {
		return fmt.Errorf("server must be an object")
	}
	if err := exactKeys(server, []string{"defaultRouting", "listener"}, nil, "server"); err != nil {
		return err
	}
	var listener map[string]json.RawMessage
	if err := json.Unmarshal(server["listener"], &listener); err != nil || listener == nil {
		return fmt.Errorf("listener must be an object")
	}
	if err := exactKeys(listener, []string{"bindAddress", "port", "protocol"}, nil, "listener"); err != nil {
		return err
	}
	var domains []json.RawMessage
	if err := json.Unmarshal(root["domains"], &domains); err != nil || domains == nil {
		return fmt.Errorf("domains must be an array")
	}
	for index, rawDomain := range domains {
		var domain map[string]json.RawMessage
		if err := json.Unmarshal(rawDomain, &domain); err != nil || domain == nil {
			return fmt.Errorf("domain %d must be an object", index)
		}
		required := []string{"guid", "pleskId", "name", "aliases", "documentRoot", "systemUser", "systemGroup", "nativeProfile", "php", "requestedRouting", "appliedRouting"}
		optional := []string{"vhostRoot", "cacheEnabled", "cachePrivateEnabled"}
		if err := exactKeys(domain, required, optional, fmt.Sprintf("domain %d", index)); err != nil {
			return err
		}
		var aliases []json.RawMessage
		if err := json.Unmarshal(domain["aliases"], &aliases); err != nil || aliases == nil {
			return fmt.Errorf("domain %d aliases must be an array", index)
		}
		var profile map[string]json.RawMessage
		if err := json.Unmarshal(domain["nativeProfile"], &profile); err != nil || profile == nil {
			return fmt.Errorf("domain %d native profile must be an object", index)
		}
		if err := exactKeys(profile, []string{"webMode", "proxyMode", "phpHandlerId"}, nil, "native profile"); err != nil {
			return err
		}
		var php map[string]json.RawMessage
		if err := json.Unmarshal(domain["php"], &php); err != nil || php == nil {
			return fmt.Errorf("domain %d PHP must be an object", index)
		}
		if err := exactKeys(php, []string{"pleskHandlerId", "version", "lsphpBinary", "socket"}, []string{"lsapi"}, "PHP"); err != nil {
			return err
		}
		if rawLSAPI, exists := php["lsapi"]; exists {
			var lsapi map[string]json.RawMessage
			if err := json.Unmarshal(rawLSAPI, &lsapi); err != nil || lsapi == nil {
				return fmt.Errorf("LSAPI must be an object")
			}
			keys := []string{"maxConnections", "children", "instances", "backlog", "initTimeout", "retryTimeout", "persistentConnection", "responseBuffering"}
			if err := exactKeys(lsapi, keys, nil, "LSAPI"); err != nil {
				return err
			}
		}
	}
	return nil
}

func exactKeys(value map[string]json.RawMessage, required, optional []string, label string) error {
	allowed := make(map[string]struct{}, len(required)+len(optional))
	for _, key := range append(append([]string{}, required...), optional...) {
		allowed[key] = struct{}{}
	}
	for _, key := range required {
		raw, exists := value[key]
		if !exists || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
			return fmt.Errorf("%s contains missing or null property %s", label, key)
		}
	}
	for key, raw := range value {
		if _, exists := allowed[key]; !exists {
			return fmt.Errorf("%s contains unknown property %s", label, key)
		}
		if bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
			return fmt.Errorf("%s contains null property %s", label, key)
		}
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
	if len(value.Domains) > maxDomains {
		return fmt.Errorf("domains exceeds the item limit")
	}

	names := make(map[string]struct{}, len(value.Domains))
	guids := make(map[string]struct{}, len(value.Domains))
	for index := range value.Domains {
		domain := &value.Domains[index]
		if err := validateDomain(domain, index); err != nil {
			return err
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

	}
	return nil
}

func validateDomain(domain *Domain, index int) error {
	label := fmt.Sprintf("domain %d", index)
	normalizedGUID := strings.ToLower(domain.GUID)
	if strings.HasPrefix(normalizedGUID, "{") && strings.HasSuffix(normalizedGUID, "}") {
		normalizedGUID = strings.TrimSuffix(strings.TrimPrefix(normalizedGUID, "{"), "}")
	}
	if !guidPattern.MatchString(normalizedGUID) {
		return fmt.Errorf("%s GUID is invalid", label)
	}
	if domain.PleskID < 1 {
		return fmt.Errorf("%s Plesk ID is invalid", label)
	}
	if err := validateDomainName(domain.Name); err != nil {
		return fmt.Errorf("%s name is invalid: %w", label, err)
	}
	if len(domain.Aliases) > maxAliases {
		return fmt.Errorf("%s aliases exceeds the item limit", label)
	}
	aliases := map[string]struct{}{domain.Name: {}}
	for _, alias := range domain.Aliases {
		if err := validateDomainName(alias); err != nil {
			return fmt.Errorf("%s alias is invalid: %w", label, err)
		}
		if _, exists := aliases[alias]; exists {
			return fmt.Errorf("%s alias is duplicated: %s", label, alias)
		}
		aliases[alias] = struct{}{}
	}
	if !safeAbsolutePath(domain.DocumentRoot) {
		return fmt.Errorf("%s document root is invalid", label)
	}
	if domain.VhostRoot != "" {
		if !safeAbsolutePath(domain.VhostRoot) {
			return fmt.Errorf("%s vhost root is invalid", label)
		}
		root := strings.TrimRight(domain.VhostRoot, "/") + "/"
		if !strings.HasPrefix(strings.TrimRight(domain.DocumentRoot, "/")+"/", root) {
			return fmt.Errorf("%s document root is outside its vhost root", label)
		}
	}
	if !accountPattern.MatchString(domain.SystemUser) || !accountPattern.MatchString(domain.SystemGroup) {
		return fmt.Errorf("%s system account is invalid", label)
	}
	if domain.NativeProfile.WebMode != "proxy" && domain.NativeProfile.WebMode != "nginx-only" {
		return fmt.Errorf("%s native web mode is invalid", label)
	}
	if (domain.NativeProfile.WebMode == "proxy") != domain.NativeProfile.ProxyMode {
		return fmt.Errorf("%s native profile is inconsistent", label)
	}
	if !handlerPattern.MatchString(domain.NativeProfile.PhpHandlerID) {
		return fmt.Errorf("%s native PHP handler is invalid", label)
	}
	if err := validatePHP(domain); err != nil {
		return fmt.Errorf("%s PHP configuration is invalid: %w", label, err)
	}
	if !validRouting(domain.RequestedRouting) || !validRouting(domain.AppliedRouting) {
		return fmt.Errorf("%s routing is invalid", label)
	}
	if domain.CachePrivateEnabled != nil && *domain.CachePrivateEnabled &&
		(domain.CacheEnabled == nil || !*domain.CacheEnabled) {
		return fmt.Errorf("%s private cache requires cache", label)
	}
	return nil
}

func validatePHP(domain *Domain) error {
	php := domain.PHP
	if !handlerPattern.MatchString(php.PleskHandlerID) {
		return fmt.Errorf("handler ID is invalid")
	}
	if !versionPattern.MatchString(php.Version) {
		return fmt.Errorf("version is invalid")
	}
	if php.LsphpBinary != "/opt/plesk/php/"+php.Version+"/bin/lsphp" {
		return fmt.Errorf("lsphp binary does not match version")
	}
	digest := fmt.Sprintf("%x", sha256.Sum256([]byte(domain.GUID)))
	expectedSocket := stateRoot + "/run/lsphp/sk-" + digest[:24] + ".sock"
	if php.Socket != expectedSocket {
		return fmt.Errorf("socket does not match GUID")
	}
	if php.Lsapi != nil {
		lsapi := php.Lsapi
		if lsapi.MaxConnections < 1 || lsapi.MaxConnections > 1000 ||
			lsapi.Children < 1 || lsapi.Children > 1000 ||
			lsapi.Instances < 1 || lsapi.Instances > 100 ||
			lsapi.Backlog < 1 || lsapi.Backlog > 10000 ||
			lsapi.InitTimeout < 1 || lsapi.InitTimeout > 3600 ||
			lsapi.RetryTimeout < 0 || lsapi.RetryTimeout > 3600 {
			return fmt.Errorf("LSAPI limits are invalid")
		}
	}
	return nil
}

func validateDomainName(value string) error {
	if len(value) == 0 || len(value) > 253 || strings.ToLower(value) != value || !domainPattern.MatchString(value) {
		return fmt.Errorf("not a valid lowercase DNS name")
	}
	return nil
}

func safeAbsolutePath(value string) bool {
	return value != "" && value != "/" && filepath.IsAbs(value) &&
		!strings.ContainsRune(value, '\x00') && !strings.Contains(value, "//") &&
		!containsParentSegment(value)
}

func containsParentSegment(value string) bool {
	for _, segment := range strings.Split(value, "/") {
		if segment == ".." {
			return true
		}
	}
	return false
}

func validRouting(value string) bool {
	return value == "native" || value == "ols"
}
