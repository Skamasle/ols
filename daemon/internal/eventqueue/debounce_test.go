package eventqueue

import (
	"testing"
	"time"
)

func TestSchedulerDebouncesByKey(t *testing.T) {
	scheduler := New(25 * time.Millisecond)
	defer scheduler.Close()

	scheduler.Submit(Event{Key: "example.test", Path: "/one", Reason: "first", When: time.Now()})
	scheduler.Submit(Event{Key: "example.test", Path: "/two", Reason: "second", When: time.Now()})

	select {
	case event := <-scheduler.Events():
		if event.Path != "/two" {
			t.Fatalf("expected latest event, got %s", event.Path)
		}
		if event.Reason != "second" {
			t.Fatalf("expected latest reason, got %s", event.Reason)
		}
	case <-time.After(200 * time.Millisecond):
		t.Fatal("debounced event was not delivered")
	}
}

func TestSchedulerIgnoresEmptyKey(t *testing.T) {
	scheduler := New(10 * time.Millisecond)
	defer scheduler.Close()

	scheduler.Submit(Event{Key: "", Path: "/ignored"})

	select {
	case <-scheduler.Events():
		t.Fatal("empty key must not emit an event")
	case <-time.After(40 * time.Millisecond):
	}
}

func TestSchedulerCloseCancelsPendingEvents(t *testing.T) {
	scheduler := New(50 * time.Millisecond)
	scheduler.Submit(Event{Key: "example.test", Path: "/pending"})
	scheduler.Close()

	if _, ok := <-scheduler.Events(); ok {
		t.Fatal("closed scheduler must not deliver pending events")
	}
}
