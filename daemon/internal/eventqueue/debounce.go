package eventqueue

import (
	"sync"
	"time"
)

type Event struct {
	Key    string
	Domain string
	Root   string
	Path   string
	Reason string
	Op     string
	When   time.Time
}

type Scheduler struct {
	delay time.Duration

	out  chan Event
	done chan struct{}

	mu       sync.Mutex
	latest   map[string]Event
	versions map[string]int
	closed   bool
	wg       sync.WaitGroup
}

func New(delay time.Duration) *Scheduler {
	return &Scheduler{
		delay:    delay,
		out:      make(chan Event, 256),
		done:     make(chan struct{}),
		latest:   make(map[string]Event),
		versions: make(map[string]int),
	}
}

func (s *Scheduler) Events() <-chan Event {
	return s.out
}

func (s *Scheduler) Submit(event Event) {
	if "" == event.Key {
		return
	}

	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return
	}

	s.versions[event.Key]++
	version := s.versions[event.Key]
	s.latest[event.Key] = event
	delay := s.delay
	s.wg.Add(1)
	s.mu.Unlock()

	time.AfterFunc(delay, func() {
		defer s.wg.Done()
		s.flush(event.Key, version)
	})
}

func (s *Scheduler) Close() {
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return
	}
	s.closed = true
	close(s.done)
	s.mu.Unlock()

	s.wg.Wait()
	close(s.out)
}

func (s *Scheduler) flush(key string, version int) {
	s.mu.Lock()
	if s.closed || s.versions[key] != version {
		s.mu.Unlock()
		return
	}
	event := s.latest[key]
	delete(s.latest, key)
	delete(s.versions, key)
	s.mu.Unlock()

	select {
	case s.out <- event:
	case <-s.done:
	}
}
