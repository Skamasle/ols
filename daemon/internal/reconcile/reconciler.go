package reconcile

import (
	"fmt"

	"skamasle-ols-agent/internal/eventqueue"
	"skamasle-ols-agent/internal/htaccessscan"
	"skamasle-ols-agent/internal/state"
)

type Action string

const (
	ActionNoop    Action = "noop"
	ActionReload  Action = "reload"
	ActionReview  Action = "review"
	ActionBlocked Action = "blocked"
	ActionMissing Action = "missing-state"
)

type Decision struct {
	Action       Action
	DomainName   string
	DomainRoot   string
	Reason       string
	Generation   int
	Routing      string
	FilesScanned int
	FindingCount int
}

type Scanner interface {
	Scan(documentRoot string) htaccessscan.Result
}

type Reconciler struct {
	store   *state.Store
	scanner Scanner
}

func New(store *state.Store, scanner Scanner) *Reconciler {
	return &Reconciler{
		store:   store,
		scanner: scanner,
	}
}

func (r *Reconciler) Decide(event eventqueue.Event) (Decision, error) {
	if nil == r.store {
		return Decision{Action: ActionMissing, Reason: "state store is unavailable"}, nil
	}

	st, err := r.store.Load()
	if err != nil {
		return Decision{Action: ActionMissing, Reason: err.Error()}, nil
	}

	domain, root, ok := r.store.FindDomainForPath(st, event.Path)
	if !ok {
		return Decision{
			Action:     ActionNoop,
			Reason:     "no matching domain for path",
			Generation: st.Generation,
		}, nil
	}

	if "ols" != domain.AppliedRouting {
		return Decision{
			Action:     ActionNoop,
			DomainName: domain.Name,
			DomainRoot: root,
			Reason:     "domain is not routed to OLS",
			Generation: st.Generation,
			Routing:    domain.AppliedRouting,
		}, nil
	}

	reason := fmt.Sprintf("ols domain %s requires reload for htaccess change under %s", domain.Name, root)
	filesScanned := 0
	findingCount := 0
	if nil == r.scanner {
		return Decision{
			Action:       ActionReload,
			DomainName:   domain.Name,
			DomainRoot:   root,
			Reason:       reason + " (htaccess scanner unavailable)",
			Generation:   st.Generation,
			Routing:      domain.AppliedRouting,
			FilesScanned: filesScanned,
			FindingCount: findingCount,
		}, nil
	}

	scan := r.scanner.Scan(domain.DocumentRoot)
	filesScanned = scan.FilesScanned
	findingCount = len(scan.Findings)
	if "blocked" == scan.Status || "review" == scan.Status {
		reason = scan.Summary() + "; reloading anyway and keeping findings for future deny-list tuning"
	}

	return Decision{
		Action:       ActionReload,
		DomainName:   domain.Name,
		DomainRoot:   root,
		Reason:       reason,
		Generation:   st.Generation,
		Routing:      domain.AppliedRouting,
		FilesScanned: filesScanned,
		FindingCount: findingCount,
	}, nil
}
