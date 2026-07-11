package main

import (
	"errors"
	"sync/atomic"
	"testing"

	"skamasle-ols-agent/internal/eventqueue"
	"skamasle-ols-agent/internal/reconcile"
)

type fakeOLSController struct {
	validateCalls int
	reloadCalls   int
	validateErr   error
	reloadErr     error
}

func (f *fakeOLSController) Validate() error {
	f.validateCalls++
	return f.validateErr
}

func (f *fakeOLSController) Reload() error {
	f.reloadCalls++
	return f.reloadErr
}

func TestExecuteDecisionDoesNotTouchOLSForFailClosedActions(t *testing.T) {
	for _, action := range []reconcile.Action{
		reconcile.ActionReview,
		reconcile.ActionBlocked,
		reconcile.ActionMissing,
		reconcile.ActionNoop,
	} {
		t.Run(string(action), func(t *testing.T) {
			manager := &fakeOLSController{}
			executeDecision(
				reconcile.Decision{Action: action, DomainName: "example.test"},
				eventqueue.Event{Key: "example.test"},
				manager,
				func(string) {},
				func(string) {},
			)
			if manager.validateCalls != 0 || manager.reloadCalls != 0 {
				t.Fatalf("action %s touched OLS: validate=%d reload=%d", action, manager.validateCalls, manager.reloadCalls)
			}
		})
	}
}

func TestExecuteDecisionReloadsOnlyAfterSuccessfulValidation(t *testing.T) {
	atomic.StoreInt64(&reloads, 0)
	manager := &fakeOLSController{}
	executeDecision(
		reconcile.Decision{Action: reconcile.ActionReload, DomainName: "example.test"},
		eventqueue.Event{Key: "example.test", Reason: "test"},
		manager,
		func(string) {},
		func(string) {},
	)
	if manager.validateCalls != 1 || manager.reloadCalls != 1 {
		t.Fatalf("expected one validation and reload, got validate=%d reload=%d", manager.validateCalls, manager.reloadCalls)
	}
	if atomic.LoadInt64(&reloads) != 1 {
		t.Fatal("successful reload was not counted")
	}
}

func TestExecuteDecisionStopsWhenValidationFails(t *testing.T) {
	atomic.StoreInt64(&reloads, 0)
	manager := &fakeOLSController{validateErr: errors.New("invalid configuration")}
	executeDecision(
		reconcile.Decision{Action: reconcile.ActionReload, DomainName: "example.test"},
		eventqueue.Event{Key: "example.test"},
		manager,
		func(string) {},
		func(string) {},
	)
	if manager.validateCalls != 1 || manager.reloadCalls != 0 {
		t.Fatalf("validation failure must stop reload, got validate=%d reload=%d", manager.validateCalls, manager.reloadCalls)
	}
	if atomic.LoadInt64(&reloads) != 0 {
		t.Fatal("failed validation changed reload count")
	}
}
