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

	if nil == r.scanner {
		return Decision{
			Action:     ActionBlocked,
			DomainName: domain.Name,
			DomainRoot: root,
			Reason:     "htaccess scanner is unavailable",
			Generation: st.Generation,
			Routing:    domain.AppliedRouting,
		}, nil
	}

	scan := r.scanner.Scan(domain.DocumentRoot)
	if "blocked" == scan.Status {
		return Decision{
			Action:       ActionBlocked,
			DomainName:   domain.Name,
			DomainRoot:   root,
			Reason:       scan.Summary(),
			Generation:   st.Generation,
			Routing:      domain.AppliedRouting,
			FilesScanned: scan.FilesScanned,
			FindingCount: len(scan.Findings),
		}, nil
	}
	if "review" == scan.Status {
		return Decision{
			Action:       ActionReview,
			DomainName:   domain.Name,
			DomainRoot:   root,
			Reason:       scan.Summary(),
			Generation:   st.Generation,
			Routing:      domain.AppliedRouting,
			FilesScanned: scan.FilesScanned,
			FindingCount: len(scan.Findings),
		}, nil
	}

	return Decision{
		Action:       ActionReload,
		DomainName:   domain.Name,
		DomainRoot:   root,
		Reason:       fmt.Sprintf("compatible htaccess change under %s", root),
		Generation:   st.Generation,
		Routing:      domain.AppliedRouting,
		FilesScanned: scan.FilesScanned,
	}, nil
}
